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
 * Baixa em lote dos documentos em trânsito (ADR 0216): o relatório de documentos parados (ADR 0215) aponta os malotes e
 * os avisos de recebimento em aberto, mas a baixa é registro por registro no formulário. Quando o portador entrega vários
 * malotes ou os Correios devolvem um lote de ARs, a administradora dá baixa em todos de uma vez, com a mesma data.
 *
 * Reusa o que já existe: cada registro é regravado pelo RegistryService::save do cadastro (pouches ou delivery_notices),
 * com as mesmas regras do formulário (data depois do envio, recebimento antes da conferência) e a auditoria update.
 *
 * - Regra pura settlement(): o que muda em cada registro.
 *   - Malote enviado: recebido na data. Com check, também conferido na data.
 *   - Malote recebido: conferido na data.
 *   - AR em trânsito: entregue na data (outcome delivered) ou devolvido (outcome returned).
 *   - Já conferido, entregue ou devolvido: recusado.
 * - Tudo ou nada: numa transação com os registros travados (FOR UPDATE). Registro de outro condomínio, apagado ou já
 *   resolvido recusa o lote antes de gravar; um recusado pelo formulário desfaz todos (RegistryValidationException com
 *   _pouch ou _notice). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro transit_settle por documento (situação antes e depois), só quando
 *   grava. Nada é enviado.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, tipo e situação antes e
 * depois: nenhum portador, conteúdo, destinatário, assunto nem código de rastreio. Até MAX_DOCUMENTS por vez.
 */
final class DocumentTransitSettlement
{
    public const MAX_DOCUMENTS = 200;
    public const OUTCOMES = ['delivered', 'returned'];
    private const KINDS = ['pouch' => ['slug' => 'pouches', 'table' => 'erp_pouches', 'input' => 'pouch_ids'],
        'notice' => ['slug' => 'delivery_notices', 'table' => 'erp_delivery_notices', 'input' => 'notice_ids']];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * O que muda no registro (regra pura); null = já resolvido, não entra.
     *
     * @return array<string, string>|null
     */
    public static function settlement(string $kind, string $status, string $on, bool $check = false, string $outcome = 'delivered'): ?array
    {
        if ($kind === 'pouch') {
            return match ($status) {
                'active' => $check ? ['status' => 'checked', 'received_on' => $on, 'checked_on' => $on] : ['status' => 'received', 'received_on' => $on],
                'received' => ['status' => 'checked', 'checked_on' => $on],
                default => null,
            };
        }
        if ($kind === 'notice' && $status === 'active') {
            return $outcome === 'returned' ? ['status' => 'returned'] : ['status' => 'delivered', 'delivered_on' => $on];
        }
        return null;
    }

    /** @param array<string, mixed> $input */
    public function settle(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $input['on'] ?? null;
        $date = is_string($on) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data da baixa: AAAA-MM-DD.');
        }
        if ($on > date('Y-m-d')) {
            throw new InvalidArgumentException('A data da baixa não pode ser futura.');
        }
        $outcome = $input['outcome'] ?? 'delivered';
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado do AR: delivered ou returned.');
        }
        $ids = [];
        foreach (self::KINDS as $kind => $meta) {
            $list = $input[$meta['input']] ?? [];
            if (!is_array($list)) {
                throw new InvalidArgumentException('Lista de documentos inválida.');
            }
            $ids[$kind] = [];
            foreach ($list as $value) {
                $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($id === false) {
                    throw new InvalidArgumentException('Lista de documentos inválida.');
                }
                $ids[$kind][(int)$id] = (int)$id;
            }
        }
        $total = count($ids['pouch']) + count($ids['notice']);
        if ($total === 0) {
            throw new InvalidArgumentException('Escolha os malotes ou os avisos de recebimento.');
        }
        if ($total > self::MAX_DOCUMENTS) {
            throw new InvalidArgumentException(sprintf('Até %d documentos por vez.', self::MAX_DOCUMENTS));
        }
        $check = filter_var($input['check'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'on' => $on, 'check' => $check, 'outcome' => $outcome, 'documents' => []];
        $this->pdo->beginTransaction();
        try {
            $plan = [];
            $errors = [];
            foreach (self::KINDS as $kind => $meta) {
                if ($ids[$kind] === []) {
                    continue;
                }
                $fields = array_keys(RegistryService::definition($meta['slug'])['fields']);
                $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                    . " FROM {$meta['table']} WHERE condominium_id = ? AND deleted_at IS NULL AND id IN (" . implode(',', array_fill(0, count($ids[$kind]), '?'))
                    . ') ORDER BY id FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], array_values($ids[$kind])));
                $rows = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $rows[(int)$row['id']] = $row;
                }
                foreach ($ids[$kind] as $id) {
                    $row = $rows[$id] ?? null;
                    $change = $row === null ? null : self::settlement($kind, (string)$row['status'], $on, $check, $outcome);
                    if ($row === null) {
                        $errors["{$kind}:{$id}"] = 'Não encontrado neste condomínio.';
                    } elseif ($change === null) {
                        $errors["{$kind}:{$id}"] = 'Já tem baixa.';
                    } else {
                        $data = [];
                        foreach ($fields as $field) {
                            $data[$field] = $row[$field];
                        }
                        $plan[] = [$kind, $id, (string)$row['status'], $data, $change];
                    }
                }
            }
            if ($errors !== []) {
                throw new RegistryValidationException($errors);
            }
            foreach ($plan as [$kind, $id, $before, $data, $change]) {
                try {
                    $registry->save($user, self::KINDS[$kind]['slug'], array_merge($data, $change), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_' . $kind => (string)$id]);
                }
                $result['documents'][] = ['kind' => $kind, 'id' => $id, 'status_before' => $before, 'status_after' => $change['status']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['documents'] as $d) {
                Audit::record('transit_settle', self::KINDS[$d['kind']]['table'], $d['id'], ['status' => $d['status_before']], ['status' => $d['status_after'], 'on' => $on]);
            }
        }
        $result['settled'] = count($result['documents']);
        $result['changed'] = $dryRun ? 0 : $result['settled'];
        return $result;
    }
}
