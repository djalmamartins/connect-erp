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
 * Bloqueio em lote das autorizações de visitantes vencidas e ainda liberadas (ADR 0235). O relatório das autorizações
 * (ADR 0234) mostra quantos visitantes seguem "Liberado" com a data "autorizado até" já passada: a portaria pode deixar
 * entrar quem não está mais autorizado. Hoje cada um é bloqueado à mão no formulário do cadastro (AP-012).
 *
 * Reusa o que já existe: cada visitante é regravado pelo RegistryService::save do cadastro "visitors" só com a situação
 * "Bloqueado" (valem as regras e a auditoria update do formulário), no mesmo desenho da baixa das visitas previstas
 * (ADR 0231). O cadastro não envia aviso; nada vai ao morador nem ao visitante. Para liberar de novo, o formulário.
 *
 * - Entram: os visitantes liberados do condomínio, sem acesso permanente, com "autorizado até" anterior a before (padrão
 *   hoje; nunca depois de hoje), ou só os visitor_ids informados. Regra pura problem(): visitante não encontrado no
 *   condomínio, já bloqueado, com acesso permanente (não vence), sem data (corrija no cadastro) ou cuja autorização ainda
 *   não venceu.
 * - Tudo ou nada, numa transação com os visitantes travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _visitor). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro expire por visitante (data vencida, dias vencidos e motivo), só
 *   quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, data vencida e dias vencidos:
 * nenhum nome, documento, telefone, unidade ou observação. Até MAX_VISITORS por vez; motivo obrigatório.
 */
final class VisitorAuthorizationExpiry
{
    public const MAX_VISITORS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a autorização pode ser bloqueada. */
    public static function problem(?array $row, string $before): ?string
    {
        $until = substr((string)($row['valid_until'] ?? ''), 0, 10);
        return match (true) {
            $row === null => 'Visitante não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O visitante já está bloqueado.',
            !empty($row['permanent_access']) => 'Acesso permanente: a autorização não vence.',
            $until === '' => 'Autorização sem data: informe a data ou marque acesso permanente no cadastro.',
            $until >= $before => 'A autorização ainda não venceu.',
            default => null,
        };
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
        if (array_key_exists('visitor_ids', $input) && $input['visitor_ids'] !== null) {
            if (!is_array($input['visitor_ids']) || $input['visitor_ids'] === []) {
                throw new InvalidArgumentException('Visitantes: informe a lista de ids ou deixe vazio para todos os vencidos e liberados.');
            }
            $ids = [];
            foreach ($input['visitor_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Visitantes: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_VISITORS) {
                throw new InvalidArgumentException(sprintf('Até %d visitantes por vez.', self::MAX_VISITORS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('visitors')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'reason' => $reason, 'visitors' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND permanent_access = 0 AND valid_until IS NOT NULL AND valid_until < ? ORDER BY valid_until, id LIMIT " . (self::MAX_VISITORS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $before]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_VISITORS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d autorizações vencidas: bloqueie em partes (data de corte menor).', self::MAX_VISITORS));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_visitor' => (string)$id]);
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
                        $field === 'valid_until' => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'visitors', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_visitor' => (string)$row['id']]);
                }
                $until = substr((string)$row['valid_until'], 0, 10);
                $result['visitors'][] = ['id' => (int)$row['id'], 'valid_until' => $until,
                    'days_expired' => (int)(new DateTimeImmutable($until))->diff(new DateTimeImmutable($today))->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['visitors'] as $v) {
                Audit::record('expire', 'erp_visitors', $v['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'valid_until' => $v['valid_until'], 'days_expired' => $v['days_expired'], 'reason' => $reason,
                        'batch' => count($result['visitors'])]);
            }
        }
        $result['count'] = count($result['visitors']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
