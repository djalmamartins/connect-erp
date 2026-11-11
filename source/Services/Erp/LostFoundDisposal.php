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
 * Destino em lote dos achados e perdidos guardados há muito tempo (ADR 0233). O regimento costuma fixar um prazo de
 * guarda; passado o prazo, o item vai para doação ou descarte. O resumo dos achados e perdidos (ADR 0232) mostra quantos
 * estão guardados há mais de 90 ou 180 dias; hoje cada um é baixado à mão no formulário (AP-134).
 *
 * Reusa o que já existe: cada item é regravado pelo RegistryService::save do cadastro "lost_found" só com a situação
 * "Descartado", a única de saída sem dono no cadastro (valem as regras e a auditoria update do formulário). O destino
 * (doação ou descarte) e o motivo ficam na auditoria; não há migration nem campo novo. Nada é enviado a ninguém.
 *
 * - Entram: os itens guardados do condomínio achados há mais de older_than_days dias (padrão 90, mínimo 30), ou só os
 *   item_ids informados. Regra pura problem(): item não encontrado no condomínio, que não está guardado (devolvido ou
 *   descartado), ou ainda dentro do prazo de guarda.
 * - Tudo ou nada, numa transação com os itens travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _item). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro dispose por item (destino, data de achado, dias guardados e motivo),
 *   só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, data de achado e dias
 * guardados: nenhum item, local, quem achou ou observação. Até MAX_ITEMS por vez; motivo obrigatório.
 */
final class LostFoundDisposal
{
    public const MAX_ITEMS = 500;
    public const MIN_DAYS = 30;
    public const DEFAULT_DAYS = 90;
    public const DESTINATIONS = ['doacao' => 'Doação', 'descarte' => 'Descarte'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o item pode receber destino. $cutoff = último dia de achado aceito. */
    public static function problem(?array $row, string $cutoff): ?string
    {
        return match (true) {
            $row === null => 'Item não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O item não está guardado (já foi devolvido ou descartado).',
            substr((string)($row['found_on'] ?? ''), 0, 10) > $cutoff => 'O item ainda está dentro do prazo de guarda.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function dispose(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $days = $input['older_than_days'] ?? null;
        $days = $days === null || $days === '' ? self::DEFAULT_DAYS : $int($days);
        if ($days === false || $days < self::MIN_DAYS || $days > 3650) {
            throw new InvalidArgumentException(sprintf('Prazo de guarda: de %d a 3650 dias.', self::MIN_DAYS));
        }
        $destination = is_string($input['destination'] ?? null) ? $input['destination'] : '';
        if (!array_key_exists($destination, self::DESTINATIONS)) {
            throw new InvalidArgumentException('Destino: doacao ou descarte.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('item_ids', $input) && $input['item_ids'] !== null) {
            if (!is_array($input['item_ids']) || $input['item_ids'] === []) {
                throw new InvalidArgumentException('Itens: informe a lista de ids ou deixe vazio para todos os guardados fora do prazo.');
            }
            $ids = [];
            foreach ($input['item_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Itens: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_ITEMS) {
                throw new InvalidArgumentException(sprintf('Até %d itens por vez.', self::MAX_ITEMS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $today = new DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $fields = array_keys(RegistryService::definition('lost_found')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'older_than_days' => (int)$days, 'found_until' => $cutoff,
            'destination' => $destination, 'destination_label' => self::DESTINATIONS[$destination], 'reason' => $reason, 'items' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_lost_found WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND found_on <= ? ORDER BY found_on, id LIMIT " . (self::MAX_ITEMS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $cutoff]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_ITEMS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d itens fora do prazo: dê destino em partes (prazo maior).', self::MAX_ITEMS));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_lost_found WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $cutoff);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_item' => (string)$id]);
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
                        in_array($field, ['found_on', 'returned_on'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'lost_found', array_merge($data, ['status' => 'discarded']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_item' => (string)$row['id']]);
                }
                $found = substr((string)$row['found_on'], 0, 10);
                $result['items'][] = ['id' => (int)$row['id'], 'found_on' => $found, 'days_kept' => (int)(new DateTimeImmutable($found))->diff($today)->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['items'] as $i) {
                Audit::record('dispose', 'erp_lost_found', $i['id'], ['status' => 'active'],
                    ['status' => 'discarded', 'destination' => $destination, 'found_on' => $i['found_on'], 'days_kept' => $i['days_kept'], 'reason' => $reason,
                        'batch' => count($result['items'])]);
            }
        }
        $result['count'] = count($result['items']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
