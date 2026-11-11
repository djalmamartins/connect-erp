<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Baixa em lote das visitas previstas que passaram sem acontecer (ADR 0231). A previsão de visita (AP-132) nasce
 * "Prevista" (status active) e fica na lista da portaria até alguém marcar "Realizada" ou "Cancelada". Quando a data (ou
 * o fim, expected_until) passa e ninguém marca nada, ela fica em aberto para sempre: polui a lista da portaria e a
 * contagem de vencidas do movimento da portaria (ADR 0230). Hoje cada uma é cancelada à mão no formulário.
 *
 * Reusa o que já existe: cada visita é regravada pelo RegistryService::save do cadastro "visit_forecasts" só com a
 * situação "Cancelada" (valem as regras e a auditoria update do formulário), como a baixa das reservas pendentes
 * (ADR 0221). O cadastro não envia aviso; nada é enviado ao morador nem ao visitante.
 *
 * - Entram: as visitas previstas em aberto do condomínio cuja data (ou o fim) é anterior a before (padrão hoje; nunca
 *   depois de hoje), ou só as forecast_ids informadas. Regra pura problem(): visita não encontrada no condomínio, que não
 *   está em aberto (realizada ou cancelada), cuja data ainda não passou, ou com acesso registrado na portaria (ligado a
 *   ela no registro de acessos, não cancelado): essa visita aconteceu e deve ser marcada como realizada. Sem ids, as
 *   visitas com acesso ficam de fora e a contagem sai em skipped_with_access.
 * - Tudo ou nada, numa transação com as visitas travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _forecast). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro expire por visita (data, fim e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id e datas: nenhum visitante,
 * documento, unidade, autorizador ou observação. Até MAX_FORECASTS por vez; motivo obrigatório.
 */
final class VisitForecastExpiry
{
    public const MAX_FORECASTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Último dia da visita: o fim, quando há, ou a data. */
    public static function lastDay(array $row): string
    {
        $until = substr((string)($row['expected_until'] ?? ''), 0, 10);
        return $until !== '' ? $until : substr((string)($row['expected_on'] ?? ''), 0, 10);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a visita pode ser baixada. */
    public static function problem(?array $row, string $before, bool $hasAccess = false): ?string
    {
        return match (true) {
            $row === null => 'Visita prevista não encontrada neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'A visita não está em aberto (já foi realizada ou cancelada).',
            self::lastDay($row) >= $before => 'A data da visita ainda não passou.',
            $hasAccess => 'A visita tem acesso registrado na portaria: marque como realizada no cadastro.',
            default => null,
        };
    }

    /**
     * Ids das visitas com acesso registrado (não cancelado) na portaria, travados na mesma transação.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function withAccess(int $condominium, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT visit_forecast_id FROM erp_access_logs WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND visit_forecast_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
        $stmt->execute(array_merge([$condominium], $ids));
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** @param array<string, mixed> $input */
    public function expire(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = date('Y-m-d');
        $before = $input['before'] ?? null;
        $before = $before === null || $before === '' ? $today : $before;
        $date = is_string($before) ? DateTimeImmutable::createFromFormat('!Y-m-d', $before) : false;
        if ($date === false || $date->format('Y-m-d') !== $before || $before > $today) {
            throw new InvalidArgumentException('Data de corte: AAAA-MM-DD, até hoje.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('forecast_ids', $input) && $input['forecast_ids'] !== null) {
            if (!is_array($input['forecast_ids']) || $input['forecast_ids'] === []) {
                throw new InvalidArgumentException('Visitas: informe a lista de ids ou deixe vazio para todas as vencidas em aberto.');
            }
            $ids = [];
            foreach ($input['forecast_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Visitas: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_FORECASTS) {
                throw new InvalidArgumentException(sprintf('Até %d visitas por vez.', self::MAX_FORECASTS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('visit_forecasts')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'reason' => $reason, 'forecasts' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visit_forecasts WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND COALESCE(expected_until, expected_on) < ? ORDER BY expected_on, id LIMIT " . (self::MAX_FORECASTS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $before]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_FORECASTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d visitas vencidas em aberto: baixe em partes (data de corte menor).', self::MAX_FORECASTS));
                }
                // Visita com acesso registrado aconteceu: fica de fora (marque como realizada no cadastro).
                $accessed = $this->withAccess((int)$condominium, array_map(static fn (array $r): int => (int)$r['id'], $rows));
                $result['skipped_with_access'] = count($accessed);
                $rows = array_values(array_filter($rows, static fn (array $r): bool => !isset($accessed[(int)$r['id']])));
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visit_forecasts WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $accessed = $this->withAccess((int)$condominium, array_keys($byId));
                $result['skipped_with_access'] = 0;
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before, isset($accessed[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_forecast' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        in_array($field, ['expected_on', 'expected_until'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'visit_forecasts', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_forecast' => (string)$row['id']]);
                }
                $result['forecasts'][] = ['id' => (int)$row['id'], 'expected_on' => substr((string)$row['expected_on'], 0, 10),
                    'expected_until' => $row['expected_until'] !== null ? substr((string)$row['expected_until'], 0, 10) : null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['forecasts'] as $f) {
                Audit::record('expire', 'erp_visit_forecasts', $f['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'expected_on' => $f['expected_on'], 'expected_until' => $f['expected_until'], 'reason' => $reason,
                        'batch' => count($result['forecasts'])]);
            }
        }
        $result['count'] = count($result['forecasts']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
