<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Publicação da prestação de contas mensal (ADR 0179). Congela o pacote do mês (AP-101, Accountability::build)
 * como foi entregue ao síndico e aos moradores, para que ele possa ser mostrado de novo igual, mesmo que
 * o mês seja reaberto e algum lançamento mude depois.
 *
 * - publish(): só com o mês fechado (AP-030). Grava o JSON do pacote com o sha256 e a versão. Publicar de novo o
 *   mesmo conteúdo devolve a versão atual, sem criar outra. Conteúdo diferente (o mês foi reaberto, corrigido e
 *   fechado de novo) vira a versão seguinte, e a anterior fica como "substituída".
 * - list(): as versões, sem o conteúdo.
 * - find(): uma versão com o conteúdo e a conferência do sha256 (intact).
 * - withdraw(): retira a versão atual, com motivo. Nada é apagado.
 * - publishAnnual() (ADR 0183): o mesmo para o pacote anual (kind = annual, competence = AAAA), com todos os meses
 *   esperados fechados. Mensal e anual têm versões separadas.
 *
 * Escopo (ADR-002): fora do escopo = CondominiumAccessDenied (404), sem dizer se a publicação existe. Tudo auditado.
 */
final class AccountabilityPublications
{
    public const MAX_LIST = 200;
    public const KINDS = ['monthly', 'annual'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private Accountability $builder;
    private AnnualAccountability $annual;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?Accountability $builder = null, ?AnnualAccountability $annual = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->builder = $builder ?? new Accountability($this->pdo, $this->scope);
        $this->annual = $annual ?? new AnnualAccountability($this->pdo, $this->scope);
    }

    /** JSON canônico do pacote (regra pura): o mesmo pacote dá sempre o mesmo texto e o mesmo sha256. */
    public static function canonical(array $pack): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return (string)json_encode($sort($pack), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function publish(User $user, ?int $condominiumId, mixed $month, mixed $notes = null): array
    {
        $pack = $this->builder->build($user, $condominiumId, $month);   // valida o mês, o condomínio e o escopo (404)
        if (($pack['closing']['status'] ?? 'open') !== 'closed') {
            throw new DomainException('Feche o mês antes de publicar a prestação de contas.');
        }
        return $this->store($user, (int)$pack['condominium_id'], 'monthly', (string)$pack['competence'], $pack, $notes);
    }

    /**
     * Publica a prestação de contas anual (ADR 0183, pacote do AnnualAccountability, ADR 0182) com competence = 'AAAA'
     * e kind = 'annual'. Exige todos os meses esperados fechados (de janeiro até o mês da data de corte), para o
     * pacote congelado não mudar depois. Mesmas regras de versão, sha256 e retirada do mensal.
     */
    public function publishAnnual(User $user, ?int $condominiumId, mixed $year, mixed $notes = null, ?string $today = null): array
    {
        $pack = $this->annual->build($user, $condominiumId, $year, $today);   // valida o ano, o condomínio e o escopo (404)
        if (($pack['months']['open'] ?? []) !== []) {
            throw new DomainException('Feche todos os meses antes de publicar a prestação de contas anual. Abertos: ' . implode(', ', $pack['months']['open']) . '.');
        }
        return $this->store($user, (int)$pack['condominium_id'], 'annual', (string)$pack['year'], $pack, $notes);
    }

    /** Grava a versão (mesmo conteúdo = devolve a atual), numa transação, com uma nova tentativa em corrida. */
    private function store(User $user, int $condominium, string $kind, string $competence, array $pack, mixed $notes): array
    {
        $note = is_string($notes) && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null;
        $json = self::canonical($pack);
        $sha = hash('sha256', $json);
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $current = $this->current($condominium, $kind, $competence);
                if ($current !== null && hash_equals((string)$current['sha256'], $sha)) {
                    $this->pdo->commit();
                    return $this->present($current, false) + ['created' => false];
                }
                $version = (int)$this->value('SELECT COALESCE(MAX(version), 0) FROM erp_accountability_publications WHERE condominium_id = ? AND kind = ? AND competence = ?',
                    [$condominium, $kind, $competence]) + 1;
                if ($current !== null) {
                    $this->pdo->prepare("UPDATE erp_accountability_publications SET status = 'superseded' WHERE id = ? AND status = 'current'")->execute([(int)$current['id']]);
                }
                $this->pdo->prepare("INSERT INTO erp_accountability_publications (condominium_id, kind, competence, version, sha256, payload_json, status, notes, published_by, published_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'current', ?, ?, ?)")->execute([$condominium, $kind, $competence, $version, $sha, $json, $note, (int)$user->id, date('Y-m-d H:i:s')]);
                $id = (int)$this->pdo->lastInsertId();
                $this->pdo->commit();
            } catch (\PDOException $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                // Duas publicações ao mesmo tempo pegaram a mesma versão (chave única): tenta de novo uma vez.
                if ($attempt === 0 && in_array($error->errorInfo[1] ?? null, [1062, 19], true)) {
                    continue;
                }
                throw $error;
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
            break;
        }
        Audit::record('create', 'erp_accountability_publications', $id, $current === null ? null : ['superseded_id' => (int)$current['id']],
            ['condominium_id' => $condominium, 'kind' => $kind, 'competence' => $competence, 'version' => $version, 'sha256' => $sha]);
        return $this->present($this->row($id), false) + ['created' => true];
    }

    /**
     * Versões, sem o conteúdo. $kind = monthly ou annual (vazio = os dois); $period = AAAA-MM no mensal ou AAAA no anual.
     *
     * @return array{condominium_id:int, kind:?string, competence:?string, truncated:bool, rows:list<array>}
     */
    public function list(User $user, ?int $condominiumId, mixed $period = null, mixed $kind = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $kind = $kind === null || $kind === '' ? null : (string)$kind;
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Tipo inválido: use monthly ou annual.');
        }
        $this->scope->assertAccess($user, $condominiumId);
        $competence = null;
        if ($period !== null && $period !== '') {
            $competence = $kind === 'annual' ? (string)AnnualBalance::year($period) : Accountability::month($period)[0];
            $kind ??= 'monthly';
        }
        $where = ['condominium_id = ?'];
        $params = [$condominiumId];
        if ($kind !== null) {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if ($competence !== null) {
            $where[] = 'competence = ?';
            $params[] = $competence;
        }
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, kind, competence, version, sha256, status, notes, published_by, published_at, withdrawn_by, withdrawn_at, withdraw_reason
            FROM erp_accountability_publications WHERE ' . implode(' AND ', $where) . ' ORDER BY competence DESC, kind, version DESC LIMIT ' . (self::MAX_LIST + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['condominium_id' => $condominiumId, 'kind' => $kind, 'competence' => $competence, 'truncated' => count($rows) > self::MAX_LIST,
            'rows' => array_map(fn (array $r): array => $this->present($r, false), array_slice($rows, 0, self::MAX_LIST))];
    }

    /** Uma versão com o pacote publicado e a conferência do conteúdo (intact = o sha256 bate). */
    public function find(User $user, int $id): array
    {
        $row = $this->row($id);
        if ($row === null || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied($row === null ? 0 : (int)$row['condominium_id']);
        }
        return $this->present($row, true);
    }

    public function withdraw(User $user, int $id, mixed $reason): array
    {
        $text = is_string($reason) ? trim((string)preg_replace('/\s+/u', ' ', $reason)) : '';
        if (mb_strlen($text) < 3 || mb_strlen($text) > 255) {
            throw new InvalidArgumentException('Informe o motivo da retirada (3 a 255 caracteres).');
        }
        $row = $this->row($id);
        if ($row === null || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied($row === null ? 0 : (int)$row['condominium_id']);
        }
        $stmt = $this->pdo->prepare("UPDATE erp_accountability_publications SET status = 'withdrawn', withdrawn_by = ?, withdrawn_at = ?, withdraw_reason = ?
            WHERE id = ? AND status = 'current'");
        $stmt->execute([(int)$user->id, date('Y-m-d H:i:s'), $text, $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Só a versão atual pode ser retirada.');
        }
        Audit::record('update', 'erp_accountability_publications', $id, ['status' => 'current'], ['status' => 'withdrawn', 'reason' => $text]);
        return $this->present((array)$this->row($id), false);
    }

    private function present(array $row, bool $withPayload): array
    {
        $out = ['id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'kind' => (string)($row['kind'] ?? 'monthly'),
            'competence' => trim((string)$row['competence']),
            'version' => (int)$row['version'], 'sha256' => (string)$row['sha256'], 'status' => (string)$row['status'], 'notes' => $row['notes'] ?? null,
            'published_by' => $row['published_by'] !== null ? (int)$row['published_by'] : null, 'published_at' => (string)$row['published_at'],
            'withdrawn_by' => isset($row['withdrawn_by']) ? (int)$row['withdrawn_by'] : null, 'withdrawn_at' => $row['withdrawn_at'] ?? null,
            'withdraw_reason' => $row['withdraw_reason'] ?? null];
        if ($withPayload) {
            $json = (string)$row['payload_json'];
            $out['intact'] = hash_equals((string)$row['sha256'], hash('sha256', $json));
            $out['payload'] = json_decode($json, true) ?? [];
        }
        return $out;
    }

    private function current(int $condominiumId, string $kind, string $competence): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_accountability_publications WHERE condominium_id = ? AND kind = ? AND competence = ? AND status = 'current' LIMIT 1");
        $stmt->execute([$condominiumId, $kind, $competence]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function row(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_accountability_publications WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function value(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
