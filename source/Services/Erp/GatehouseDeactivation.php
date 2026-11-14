<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Portarias paradas inativadas em lote (ADR 0295). A situação das portarias (ADR 0294) aponta em idle_ids as portarias
 * ativas sem acesso no período: a portaria desativada no prédio (troca para remota, fusão de guaritas) segue ativa no
 * cadastro e aparece nos formulários de acesso e de entrega. Inativar era uma por uma, pelo cadastro.
 *
 * Reusa o que já existe: os cadastros do registro (RegistryService::definition: gatehouses para a tabela, a permissão e
 * os tipos; access_logs e deliveries para as tabelas), as regras das listas (sem os excluídos; acesso cancelado não conta;
 * encomenda aguardando retirada = status active) e a janela de dias da situação (GatehouseOverview: os últimos days dias
 * até hoje, no dia do banco). Inativar é o status inactive do cadastro (com updated_by); nada é apagado, e os acessos e as
 * encomendas já registrados continuam apontando a portaria.
 *
 * - Entram: sem gatehouse_ids, as portarias ativas do condomínio sem acesso nos últimos days dias (padrão DEFAULT_DAYS, de
 *   MIN_DAYS a MAX_DAYS) e sem encomenda aguardando retirada; as paradas com encomenda aguardando ficam de fora e são
 *   contadas em skipped_pending. Com gatehouse_ids, só as informadas. Regra pura problem(): não encontrada no
 *   condomínio, já inativa, com encomenda aguardando retirada ou com acesso no período.
 * - Tudo ou nada, numa transação com as portarias travadas (FOR UPDATE): a trava da portaria segura também o registro de
 *   um acesso ou de uma encomenda nela até o fim (a chave estrangeira confere a portaria). Uma recusada desfaz todas
 *   (RegistryValidationException com _form e _gatehouse). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por portaria (status, dias, último acesso, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo e a data do último
 * acesso: nenhum nome de portaria, responsável, telefone nem pessoa. Até MAX_GATEHOUSES por vez; motivo obrigatório.
 */
final class GatehouseDeactivation
{
    public const MAX_GATEHOUSES = 500;
    public const DEFAULT_DAYS = 90;
    public const MIN_DAYS = 30;
    public const MAX_DAYS = 3650;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa (regra pura); null quando a portaria pode ser inativada. */
    public static function problem(?array $row, ?string $lastEntryOn, int $pending, string $from, int $days): ?string
    {
        return match (true) {
            $row === null => 'Portaria não encontrada neste condomínio.',
            $row['status'] !== 'active' => 'A portaria já está inativa.',
            $pending > 0 => 'Há encomenda aguardando retirada nesta portaria: dê baixa antes de inativar.',
            $lastEntryOn !== null && $lastEntryOn >= $from => sprintf('A portaria registrou acesso nos últimos %d dias.', $days),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $days = self::DEFAULT_DAYS;
        if (array_key_exists('days', $input) && $input['days'] !== null && $input['days'] !== '') {
            $days = $int($input['days']);
            if ($days === false || $days < self::MIN_DAYS || $days > self::MAX_DAYS) {
                throw new InvalidArgumentException(sprintf('Dias sem acesso: de %d a %d.', self::MIN_DAYS, self::MAX_DAYS));
            }
        }
        $ids = null;
        if (array_key_exists('gatehouse_ids', $input) && $input['gatehouse_ids'] !== null) {
            if (!is_array($input['gatehouse_ids']) || $input['gatehouse_ids'] === []) {
                throw new InvalidArgumentException('Portarias: informe a lista de ids ou deixe vazio para todas as paradas.');
            }
            $ids = [];
            foreach ($input['gatehouse_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Portarias: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_GATEHOUSES) {
                throw new InvalidArgumentException(sprintf('Até %d portarias por vez.', self::MAX_GATEHOUSES));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O dia do banco, para comparar com as datas gravadas; a janela é a da situação das portarias.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $from = (new \DateTimeImmutable($today))->modify(sprintf('-%d days', (int)$days - 1))->format('Y-m-d');
        $gatehouses = (string)RegistryService::definition('gatehouses')['table'];
        $logs = (string)RegistryService::definition('access_logs')['table'];
        $deliveries = (string)RegistryService::definition('deliveries')['table'];
        $kinds = GatehouseOverview::kinds();

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'days' => (int)$days, 'from' => $from, 'to' => $today, 'gatehouses' => [],
            'skipped_pending' => 0];
        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT id, kind, status FROM {$gatehouses} WHERE condominium_id = ? AND deleted_at IS NULL";
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'active'";
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status']];
            }
            $last = [];
            $pending = [];
            if ($byId !== []) {
                $in = implode(',', array_fill(0, count($byId), '?'));
                $stmt = $this->pdo->prepare("SELECT gatehouse_id, MAX(entered_on) AS last_on FROM {$logs} WHERE condominium_id = ? AND deleted_at IS NULL
                    AND status = 'active' AND gatehouse_id IN ({$in}) GROUP BY gatehouse_id");
                $stmt->execute(array_merge([(int)$condominium], array_keys($byId)));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $last[(int)$r['gatehouse_id']] = substr((string)$r['last_on'], 0, 10);
                }
                $stmt = $this->pdo->prepare("SELECT gatehouse_id, COUNT(*) AS n FROM {$deliveries} WHERE condominium_id = ? AND deleted_at IS NULL
                    AND status = 'active' AND gatehouse_id IN ({$in}) GROUP BY gatehouse_id");
                $stmt->execute(array_merge([(int)$condominium], array_keys($byId)));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pending[(int)$r['gatehouse_id']] = (int)$r['n'];
                }
            }
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $row) {
                    $problem = self::problem($row, $last[$id] ?? null, $pending[$id] ?? 0, $from, (int)$days);
                    if ($problem === null) {
                        $rows[] = $row;
                    } elseif (($pending[$id] ?? 0) > 0 && !(isset($last[$id]) && $last[$id] >= $from)) {
                        $result['skipped_pending']++;   // parada, mas com encomenda aguardando
                    }
                }
                if (count($rows) > self::MAX_GATEHOUSES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d portarias paradas: inative em partes (escolha os ids).', self::MAX_GATEHOUSES));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $last[$id] ?? null, $pending[$id] ?? 0, $from, (int)$days);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_gatehouse' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE {$gatehouses} SET status = 'inactive', updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'active'
                AND deleted_at IS NULL");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Uma portaria mudou de situação enquanto a inativação era pedida.');
                }
                $result['gatehouses'][] = ['id' => $row['id'], 'kind' => $row['kind'], 'last_entry_on' => $last[$row['id']] ?? null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['gatehouses'] as $g) {
                Audit::record('update', $gatehouses, $g['id'], ['status' => 'active'], ['status' => 'inactive', 'days' => (int)$days,
                    'last_entry_on' => $g['last_entry_on'], 'reason' => $reason, 'batch' => count($result['gatehouses'])]);
            }
        }
        $byKind = array_fill_keys(array_keys($kinds), 0);
        foreach ($result['gatehouses'] as $g) {
            if (isset($byKind[$g['kind']])) {
                $byKind[$g['kind']]++;
            }
        }
        $result['by_kind'] = array_map(static fn (string $kind, int $n): array => ['kind' => $kind, 'kind_label' => (string)$kinds[$kind], 'gatehouses' => $n],
            array_keys($byKind), array_values($byKind));
        $result['count'] = count($result['gatehouses']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
