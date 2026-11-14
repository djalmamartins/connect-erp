<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Prévias de importação paradas descartadas em lote (ADR 0285). A situação das importações (ADR 0284) aponta em
 * pending_ids as prévias que ninguém aplicou: o arquivo foi conferido e esquecido. A prévia parada confunde (parece
 * pendente) e, se alguém a aplicar meses depois, grava dados velhos. Não havia como descartar: a prévia ficava para
 * sempre.
 *
 * Descartar é a situação discarded (migration 20261114_0001), com quem descartou, quando e o motivo, só para quem ainda
 * está em prévia. O arquivo fica guardado e nada é aplicado nem apagado. A prévia descartada não pode ser aplicada
 * (Imports::apply recusa); enviar o mesmo arquivo de novo a volta para prévia (Imports::preview). Os tipos vêm de
 * Imports::KINDS, e o limite e a regra de parado são os da fila de avisos (NotificationStaleCancellation::cutoff e
 * stale), sobre a data do envio da prévia, no relógio do banco.
 *
 * - Entram: as prévias do condomínio enviadas há mais de days dias (padrão DEFAULT_DAYS, de 1 a MAX_DAYS), ou só os
 *   import_ids informados. Regra pura problem(): não encontrada no condomínio, não está em prévia (já aplicada ou
 *   descartada) ou mais nova que o limite.
 * - Tudo ou nada, numa transação com as importações do condomínio travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form e _import). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro discard por importação (dias, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo e contagens: nenhum nome
 * de arquivo, caminho, hash, resumo nem quem enviou. Até MAX_IMPORTS por vez; motivo obrigatório.
 */
final class ImportDiscard
{
    public const MAX_IMPORTS = 500;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa (regra pura); null quando a prévia pode ser descartada. */
    public static function problem(?array $row, \DateTimeImmutable $cutoff, int $days): ?string
    {
        return match (true) {
            $row === null => 'Importação não encontrada neste condomínio.',
            $row['status'] !== 'previewed' => 'A importação não está em prévia (já foi aplicada ou descartada).',
            !NotificationStaleCancellation::stale($row['created_at'], null, $cutoff) => sprintf('A prévia é mais nova que o limite de %d dias.', $days),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function discard(User $user, array $input, ?\DateTimeImmutable $now = null): array
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
            if ($days === false || $days > self::MAX_DAYS) {
                throw new InvalidArgumentException(sprintf('Dias parada: de 1 a %d.', self::MAX_DAYS));
            }
        }
        $ids = null;
        if (array_key_exists('import_ids', $input) && $input['import_ids'] !== null) {
            if (!is_array($input['import_ids']) || $input['import_ids'] === []) {
                throw new InvalidArgumentException('Importações: informe a lista de ids ou deixe vazio para todas as prévias paradas.');
            }
            $ids = [];
            foreach ($input['import_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Importações: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_IMPORTS) {
                throw new InvalidArgumentException(sprintf('Até %d importações por vez.', self::MAX_IMPORTS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O relógio do banco: created_at foi gravado por ele.
        $now ??= new \DateTimeImmutable((string)$this->pdo->query('SELECT NOW()')->fetchColumn());
        $cutoff = NotificationStaleCancellation::cutoff($now, (int)$days);

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'days' => (int)$days,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'), 'imports' => []];
        $this->pdo->beginTransaction();
        try {
            $sql = 'SELECT id, kind, status, created_at FROM erp_imports WHERE condominium_id = ?';
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'previewed'";
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'], 'created_at' => (string)$r['created_at']];
            }
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $row) {
                    if (NotificationStaleCancellation::stale($row['created_at'], null, $cutoff)) {
                        $rows[] = $row;
                    }
                }
                if (count($rows) > self::MAX_IMPORTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d prévias paradas: descarte em partes (escolha os ids).', self::MAX_IMPORTS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $cutoff, (int)$days);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_import' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE erp_imports SET status = 'discarded', discarded_by = ?, discarded_at = NOW(), discard_reason = ?
                WHERE id = ? AND condominium_id = ? AND status = 'previewed'");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $reason, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Uma importação mudou de situação enquanto o descarte era pedido.');
                }
                $result['imports'][] = ['id' => $row['id'], 'kind' => $row['kind']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['imports'] as $i) {
                Audit::record('discard', 'erp_imports', $i['id'], ['status' => 'previewed'], ['status' => 'discarded', 'days' => (int)$days, 'reason' => $reason,
                    'batch' => count($result['imports'])]);
            }
        }
        $byKind = array_fill_keys(array_keys(Imports::KINDS), 0);
        foreach ($result['imports'] as $i) {
            if (isset($byKind[$i['kind']])) {
                $byKind[$i['kind']]++;
            }
        }
        $result['by_kind'] = array_map(static fn (string $kind, int $n): array => ['kind' => $kind, 'kind_label' => (string)Imports::KINDS[$kind]['label'], 'imports' => $n],
            array_keys($byKind), array_values($byKind));
        $result['count'] = count($result['imports']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
