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
 * Banners encerrados ainda ativos inativados em lote (ADR 0287). A situação dos banners (ADR 0286) aponta em
 * ended_active_ids os banners que já acabaram e continuam ativos: esquecidos no cadastro, um por um. Aqui eles são
 * inativados de uma vez: só o status passa de active para inactive (com updated_by); nada é apagado e as datas, o título
 * e o texto não mudam.
 *
 * Reusa o que já existe: o cadastro de banners do registro (RegistryService::definition('banners'): a tabela e a
 * permissão erp.settings, que o controller exige em view e manage), a regra de quem pode alterar um registro sem
 * condomínio (RegistryService::canWrite: só quem acessa todos os condomínios) e a situação no dia
 * (BannerOverview::state, no dia do banco).
 *
 * - Entram: sem banner_ids, os banners encerrados ainda ativos do condomínio e os de todos os condomínios (sem
 *   condomínio). Os de todos só entram para quem pode alterá-los (canWrite); para os outros, ficam de fora e são
 *   contados em skipped_global. Com banner_ids, só os informados. Regra pura problem(): não encontrado (neste condomínio
 *   ou entre os de todos), já inativo, ainda não acabou (no ar ou agendado) ou de todos os condomínios sem poder alterar.
 * - Tudo ou nada, numa transação com os banners travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _banner). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro deactivate por banner (motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, alcance e contagens: nenhum
 * título, texto nem quem cadastrou. Até MAX_BANNERS por vez; motivo obrigatório.
 */
final class BannerDeactivation
{
    public const MAX_BANNERS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /** Motivo da recusa (regra pura); null quando o banner pode ser inativado. */
    public static function problem(?array $row, string $today, bool $canWriteGlobal): ?string
    {
        return match (true) {
            $row === null => 'Banner não encontrado neste condomínio nem entre os de todos os condomínios.',
            $row['status'] !== 'active' => 'O banner já está inativo.',
            BannerOverview::state($row['status'], $row['starts_on'], $row['ends_on'], $today) !== 'ended' => 'O banner ainda não acabou (está no ar ou agendado).',
            $row['global'] && !$canWriteGlobal => 'Somente quem acessa todos os condomínios pode inativar um banner de todos os condomínios.',
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
        $ids = null;
        if (array_key_exists('banner_ids', $input) && $input['banner_ids'] !== null) {
            if (!is_array($input['banner_ids']) || $input['banner_ids'] === []) {
                throw new InvalidArgumentException('Banners: informe a lista de ids ou deixe vazio para todos os encerrados ainda ativos.');
            }
            $ids = [];
            foreach ($input['banner_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Banners: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_BANNERS) {
                throw new InvalidArgumentException(sprintf('Até %d banners por vez.', self::MAX_BANNERS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O dia do banco, para comparar com as datas gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $canWriteGlobal = $this->registry->canWrite($user, 'banners', ['condominium_id' => null]);
        $table = (string)RegistryService::definition('banners')['table'];

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'on' => $today, 'banners' => [], 'skipped_global' => 0];
        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT id, condominium_id, status, starts_on, ends_on FROM {$table} WHERE deleted_at IS NULL AND (condominium_id = ? OR condominium_id IS NULL)";
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'active' AND ends_on < ?";
                $params[] = $today;
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'global' => $r['condominium_id'] === null, 'status' => (string)$r['status'],
                    'starts_on' => substr((string)$r['starts_on'], 0, 10), 'ends_on' => substr((string)$r['ends_on'], 0, 10)];
            }
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $row) {
                    $problem = self::problem($row, $today, $canWriteGlobal);
                    if ($problem === null) {
                        $rows[] = $row;
                    } elseif ($row['global'] && BannerOverview::state($row['status'], $row['starts_on'], $row['ends_on'], $today) === 'ended') {
                        $result['skipped_global']++;   // de todos os condomínios, sem poder alterar
                    }
                }
                if (count($rows) > self::MAX_BANNERS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d banners encerrados ainda ativos: inative em partes (escolha os ids).', self::MAX_BANNERS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $today, $canWriteGlobal);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_banner' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE {$table} SET status = 'inactive', updated_by = ? WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $row['id']]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Um banner mudou de situação enquanto a inativação era pedida.');
                }
                $result['banners'][] = ['id' => $row['id'], 'scope' => $row['global'] ? 'global' : 'condominium'];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['banners'] as $b) {
                Audit::record('deactivate', $table, $b['id'], ['status' => 'active'], ['status' => 'inactive', 'reason' => $reason, 'batch' => count($result['banners'])]);
            }
        }
        $byScope = array_fill_keys(array_keys(BannerOverview::SCOPES), 0);
        foreach ($result['banners'] as $b) {
            $byScope[$b['scope']]++;
        }
        $result['by_scope'] = array_map(static fn (string $scope, int $n): array => ['scope' => $scope, 'scope_label' => BannerOverview::SCOPES[$scope], 'banners' => $n],
            array_keys($byScope), array_values($byScope));
        $result['count'] = count($result['banners']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
