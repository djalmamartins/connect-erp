<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Bloqueio e liberação em lote das áreas comuns (ADR 0229). Para uma obra, uma dedetização ou o fim de uma
 * reforma, o síndico bloqueia ou libera várias áreas de uma vez; hoje é área por área no formulário do cadastro (AP-008),
 * campo "Reserva".
 *
 * Reusa o que já existe: cada área é regravada pelo RegistryService::save do cadastro "common_areas" só com o novo modo
 * de reserva (valem as regras, a chave única e a auditoria update do formulário). Os modos são os do cadastro (Exige
 * aprovação, Livre, Bloqueada, Não reservável). A reserva já confere o modo da área (RegistryService::reservationRules):
 * área bloqueada ou não reservável recusa reserva nova.
 *
 * - Regra pura problem(): área não encontrada no condomínio, inativa, já no modo pedido, ou, para bloquear ou tornar não
 *   reservável, com reserva aguardando aprovação ou confirmada de hoje em diante (a reserva da área bloqueada não pode
 *   mais ser editada no formulário; cancele ou remarque antes). Nenhuma reserva é tocada.
 * - Tudo ou nada, numa transação com as áreas e as reservas futuras travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _area). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro booking_mode por área (de, para e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, nome e modo das áreas e a
 * contagem de reservas futuras: nenhum morador ou unidade. Nenhum aviso é enviado. Até MAX_AREAS por vez; motivo
 * obrigatório.
 */
final class CommonAreaBookingMode
{
    public const MAX_AREAS = 100;
    public const CLOSED = ['blocked', 'none'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Modos de reserva do cadastro das áreas comuns. @return array<string, string> */
    public static function modes(): array
    {
        return RegistryService::definition('common_areas')['fields']['booking_mode']['options'];
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a área pode mudar para $mode. */
    public static function problem(?array $row, string $mode, int $upcoming): ?string
    {
        return match (true) {
            $row === null => 'Área não encontrada neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Área inativa: ative no cadastro antes.',
            ($row['booking_mode'] ?? '') === $mode => 'A área já está nesse modo de reserva.',
            in_array($mode, self::CLOSED, true) && $upcoming > 0 => sprintf('A área tem %d reserva(s) aguardando ou confirmada(s) de hoje em diante: cancele ou remarque antes.', $upcoming),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function change(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $mode = is_string($input['booking_mode'] ?? null) ? $input['booking_mode'] : '';
        $modes = self::modes();
        if (!array_key_exists($mode, $modes)) {
            throw new InvalidArgumentException('Modo de reserva: ' . implode(', ', array_keys($modes)) . '.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        if (!is_array($input['area_ids'] ?? null) || $input['area_ids'] === []) {
            throw new InvalidArgumentException('Áreas: informe a lista de ids.');
        }
        $ids = [];
        foreach ($input['area_ids'] as $value) {
            $id = $int($value);
            if ($id === false) {
                throw new InvalidArgumentException('Áreas: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_AREAS) {
            throw new InvalidArgumentException(sprintf('Até %d áreas por vez.', self::MAX_AREAS));
        }
        $ids = array_values($ids);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $definition = RegistryService::definition('common_areas');
        $fields = array_keys($definition['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $today = date('Y-m-d');
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'booking_mode' => $mode, 'booking_mode_label' => $modes[$mode],
            'reason' => $reason, 'areas' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_common_areas WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = $r;
            }
            $stmt = $this->pdo->prepare("SELECT common_area_id FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL
                AND status IN ('active', 'confirmed') AND reserved_on >= ? AND common_area_id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium, $today], $ids));
            $upcoming = array_count_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            foreach ($ids as $id) {
                $row = $byId[$id] ?? null;
                $problem = self::problem($row, $mode, $upcoming[$id] ?? 0);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_area' => (string)$id]);
                }
                $data = ['status' => (string)$row['status']];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $type = $definition['fields'][$field]['type'] ?? 'text';
                    $data[$field] = match (true) {
                        $value === null => null,
                        $type === 'money' => number_format(((int)$value) / 100, 2, ',', ''),
                        $type === 'time' => substr((string)$value, 0, 5),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'common_areas', array_merge($data, ['booking_mode' => $mode]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_area' => (string)$id]);
                }
                $result['areas'][] = ['id' => $id, 'name' => (string)$row['name'], 'from_mode' => (string)$row['booking_mode'], 'to_mode' => $mode,
                    'upcoming_reservations' => $upcoming[$id] ?? 0];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['areas'] as $a) {
                Audit::record('booking_mode', 'erp_common_areas', $a['id'], ['booking_mode' => $a['from_mode']],
                    ['booking_mode' => $mode, 'reason' => $reason, 'batch' => count($result['areas'])]);
            }
        }
        $result['count'] = count($result['areas']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
