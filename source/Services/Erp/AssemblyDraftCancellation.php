<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Rascunhos de assembleia esquecidos cancelados em lote (ADR 0291). O histórico das assembleias (ADR 0264) conta como
 * pendentes os rascunhos que nunca foram convocados, mesmo quando a data da primeira convocação já passou: a assembleia
 * não aconteceu, o rascunho ficou no cadastro e segue contando. Cancelar era um por um (Assemblies::cancel).
 *
 * Reusa o que já existe: as regras e a gravação de Assemblies::cancel para o rascunho (a situação passa de draft para
 * cancelled, com o motivo em cancel_reason e quem cancelou em updated_by; o UPDATE só vale se ainda for rascunho), o
 * motivo pela mesma regra (Announcements::line, de 5 a 255 caracteres) e o mesmo registro de auditoria update de
 * erp_assemblies. Só rascunhos: a assembleia convocada tem edital, e cancelar o edital é um passo à parte do
 * Assemblies::cancel (fora desta transação); ela segue sendo cancelada uma por uma. O relógio é o do banco.
 *
 * - Entram: sem assembly_ids, os rascunhos do condomínio com a primeira convocação antes de agora; com assembly_ids, só os
 *   informados. Regra pura problem(): não encontrada no condomínio, não é rascunho (convocada, aberta, encerrada ou
 *   cancelada) ou a primeira convocação ainda não passou (o rascunho ainda pode ser convocado).
 * - Tudo ou nada, numa transação com as assembleias travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form e _assembly). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por assembleia (como o do cancelamento de uma, com o tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo, a data da primeira
 * convocação e os dias desde ela: nenhum título, local, pauta nem quem criou. Até MAX_ASSEMBLIES por vez; motivo
 * obrigatório.
 */
final class AssemblyDraftCancellation
{
    public const MAX_ASSEMBLIES = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa (regra pura); null quando o rascunho pode ser cancelado. */
    public static function problem(?array $row, string $now): ?string
    {
        return match (true) {
            $row === null => 'Assembleia não encontrada neste condomínio.',
            $row['status'] !== 'draft' => 'Só rascunhos entram no lote. A assembleia convocada tem edital: cancele uma por uma.',
            $row['first_call_at'] >= $now => 'A primeira convocação ainda não passou: o rascunho ainda pode ser convocado.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input, ?string $now = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = Announcements::line($input['reason'] ?? null, 5, 255, 'o motivo do cancelamento');
        $ids = null;
        if (array_key_exists('assembly_ids', $input) && $input['assembly_ids'] !== null) {
            if (!is_array($input['assembly_ids']) || $input['assembly_ids'] === []) {
                throw new InvalidArgumentException('Assembleias: informe a lista de ids ou deixe vazio para todos os rascunhos vencidos.');
            }
            $ids = [];
            foreach ($input['assembly_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Assembleias: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_ASSEMBLIES) {
                throw new InvalidArgumentException(sprintf('Até %d assembleias por vez.', self::MAX_ASSEMBLIES));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O relógio do banco: first_call_at é comparado com ele.
        $now ??= (string)$this->pdo->query('SELECT NOW()')->fetchColumn();
        $now = substr($now, 0, 19);

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'now' => $now, 'assemblies' => []];
        $this->pdo->beginTransaction();
        try {
            $sql = 'SELECT id, kind, status, first_call_at FROM erp_assemblies WHERE condominium_id = ?';
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'draft' AND first_call_at < ?";
                $params[] = $now;
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'],
                    'first_call_at' => substr((string)$r['first_call_at'], 0, 19)];
            }
            $rows = [];
            if ($ids === null) {
                $rows = array_values($byId);
                if (count($rows) > self::MAX_ASSEMBLIES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d rascunhos vencidos: cancele em partes (escolha os ids).', self::MAX_ASSEMBLIES));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $now);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_assembly' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE erp_assemblies SET status = 'cancelled', cancel_reason = ?, updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'draft'");
            $today = new \DateTimeImmutable(substr($now, 0, 10));
            foreach ($rows as $row) {
                $update->execute([$reason, (int)$user->id, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Uma assembleia mudou de situação enquanto o cancelamento era pedido.');
                }
                $firstCall = substr($row['first_call_at'], 0, 10);
                $result['assemblies'][] = ['id' => $row['id'], 'kind' => $row['kind'], 'first_call_on' => $firstCall,
                    'days_past' => (int)(new \DateTimeImmutable($firstCall))->diff($today)->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['assemblies'] as $a) {
                Audit::record('update', 'erp_assemblies', $a['id'], ['status' => 'draft'], ['status' => 'cancelled', 'cancel_reason' => $reason, 'notice_stopped' => 0,
                    'batch' => count($result['assemblies'])]);
            }
        }
        $byKind = array_fill_keys(Assemblies::KINDS, 0);
        foreach ($result['assemblies'] as $a) {
            if (isset($byKind[$a['kind']])) {
                $byKind[$a['kind']]++;
            }
        }
        $result['by_kind'] = array_map(static fn (string $kind, int $n): array => ['kind' => $kind, 'kind_label' => AssemblyHistory::LABELS[$kind] ?? $kind, 'assemblies' => $n],
            array_keys($byKind), array_values($byKind));
        $result['count'] = count($result['assemblies']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
