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
 * Alíquotas próprias que só repetem o padrão inativadas em lote (ADR 0293). A cobertura das alíquotas (ADR 0262) mostra,
 * por imposto, se o condomínio substitui o padrão da Connect (overrides_default). Quando as alíquotas próprias de um
 * imposto são exatamente as do padrão (mesmas taxas e mesma aplicação), a substituição não muda nada hoje, mas congela o
 * condomínio: se o padrão for atualizado, ele segue com a cópia antiga. Inativar a cópia faz o condomínio voltar a seguir o
 * padrão, sem mudar a alíquota em vigor.
 *
 * Reusa o que já existe: o cadastro de impostos (RegistryService::definition('taxes'): a tabela, a permissão
 * erp.settings, que o controller exige em view e manage, e os impostos do campo kind) e a regra de origem da cobertura
 * (TaxCoverage: havendo própria ativa do imposto, valem só as próprias; senão, as padrão). Inativar é o status inactive
 * do cadastro (com updated_by); nada é apagado e o padrão nunca é tocado.
 *
 * - O lote é por imposto: entram todas as alíquotas próprias ativas dos impostos redundantes, porque inativar só parte
 *   delas mudaria a alíquota em vigor. Sem kinds, entram todos os impostos redundantes do condomínio; com kinds, só os
 *   informados. Regra pura problem(): imposto desconhecido, sem alíquota própria ativa, sem padrão ativo ou com as
 *   próprias diferentes do padrão (redundant() compara o conjunto de taxa e aplicação).
 * - Tudo ou nada, numa transação com as alíquotas do condomínio e do padrão travadas (FOR UPDATE): um imposto recusado
 *   desfaz todos (RegistryValidationException com _form e _kind). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por alíquota (status, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem impostos, ids, taxas e a aplicação:
 * nenhum nome de alíquota nem observação. Até MAX_TAXES alíquotas por vez; motivo obrigatório.
 */
final class TaxOverrideCleanup
{
    public const MAX_TAXES = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * As próprias repetem o padrão (regra pura): o mesmo conjunto de taxa e aplicação, contando as repetidas.
     *
     * @param list<array{rate:float, applies_to:string}> $own
     * @param list<array{rate:float, applies_to:string}> $default
     */
    public static function redundant(array $own, array $default): bool
    {
        $key = static fn (array $t): string => sprintf('%.2f|%s', round($t['rate'], 2), $t['applies_to']);
        $a = array_map($key, $own);
        $b = array_map($key, $default);
        sort($a);
        sort($b);
        return $a !== [] && $a === $b;
    }

    /** Motivo da recusa (regra pura); null quando o imposto pode voltar ao padrão. */
    public static function problem(?string $kindLabel, array $own, array $default): ?string
    {
        return match (true) {
            $kindLabel === null => 'Imposto desconhecido no cadastro.',
            $own === [] => 'O condomínio não tem alíquota própria ativa deste imposto.',
            $default === [] => 'Não há alíquota padrão ativa deste imposto: a própria é a única que vale.',
            !self::redundant($own, $default) => 'As alíquotas próprias são diferentes do padrão (taxa ou aplicação): inativar mudaria a alíquota em vigor.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input): array
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
        $definition = RegistryService::definition('taxes');
        $labels = (array)$definition['fields']['kind']['options'];
        $kinds = null;
        if (array_key_exists('kinds', $input) && $input['kinds'] !== null) {
            if (!is_array($input['kinds']) || $input['kinds'] === []) {
                throw new InvalidArgumentException('Impostos: informe a lista ou deixe vazio para todos os que repetem o padrão.');
            }
            $kinds = [];
            foreach ($input['kinds'] as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 40) {
                    throw new InvalidArgumentException('Impostos: informe a lista de impostos.');
                }
                $kinds[$value] = $value;
            }
            $kinds = array_values($kinds);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        $table = (string)$definition['table'];

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'kinds' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, condominium_id, kind, rate, applies_to FROM {$table}
                WHERE (condominium_id = ? OR condominium_id IS NULL) AND deleted_at IS NULL AND status = 'active' ORDER BY id FOR UPDATE");
            $stmt->execute([(int)$condominium]);
            $own = [];
            $default = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tax = ['id' => (int)$r['id'], 'rate' => (float)$r['rate'], 'applies_to' => (string)$r['applies_to']];
                if ($r['condominium_id'] === null) {
                    $default[(string)$r['kind']][] = $tax;
                } else {
                    $own[(string)$r['kind']][] = $tax;
                }
            }
            $chosen = [];
            if ($kinds === null) {
                foreach (array_keys($labels) as $kind) {
                    if (self::problem((string)$labels[$kind], $own[$kind] ?? [], $default[$kind] ?? []) === null) {
                        $chosen[] = $kind;
                    }
                }
            } else {
                foreach ($kinds as $kind) {
                    $problem = self::problem(isset($labels[$kind]) ? (string)$labels[$kind] : null, $own[$kind] ?? [], $default[$kind] ?? []);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_kind' => $kind]);
                    }
                    $chosen[] = $kind;
                }
            }
            if (array_sum(array_map(static fn (string $k): int => count($own[$k]), $chosen)) > self::MAX_TAXES) {
                throw new InvalidArgumentException(sprintf('Mais de %d alíquotas: inative em partes (escolha os impostos).', self::MAX_TAXES));
            }
            $update = $this->pdo->prepare("UPDATE {$table} SET status = 'inactive', updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'active' AND deleted_at IS NULL");
            foreach ($chosen as $kind) {
                $ids = [];
                foreach ($own[$kind] as $tax) {
                    $update->execute([(int)$user->id, $tax['id'], (int)$condominium]);
                    if ($update->rowCount() !== 1) {
                        throw new DomainException('Uma alíquota mudou enquanto a inativação era pedida.');
                    }
                    $ids[] = $tax['id'];
                }
                $result['kinds'][] = ['kind' => $kind, 'kind_label' => (string)$labels[$kind], 'tax_ids' => $ids,
                    'rates' => array_map(static fn (array $t): array => ['rate' => round($t['rate'], 2), 'applies_to' => $t['applies_to']], $own[$kind])];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $count = array_sum(array_map(static fn (array $k): int => count($k['tax_ids']), $result['kinds']));
        if (!$dryRun) {
            foreach ($result['kinds'] as $k) {
                foreach ($k['tax_ids'] as $id) {
                    Audit::record('update', $table, $id, ['status' => 'active'], ['status' => 'inactive', 'reason' => $reason, 'kind' => $k['kind'], 'batch' => $count]);
                }
            }
        }
        $result['count'] = $count;
        $result['changed'] = $dryRun ? 0 : $count;
        return $result;
    }
}
