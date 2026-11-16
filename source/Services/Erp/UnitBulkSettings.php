<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Support\Audit;

/**
 * Configuração das unidades em massa (revisão de 15/11, etapa 2, ADR 0311; opções do modelo 1): as unidades marcadas na
 * lista recebem de uma vez as configurações de cobrança escolhidas. Só muda o que veio no corpo: o que ficou em
 * "Manter como está" não vai e não é tocado.
 *
 * - Campos aceitos: os de SETTINGS, validados pela mesma regra do cadastro da unidade (RegistryValidator), e
 *   partner_agency_id (imobiliária ativa, ou null para tirar).
 * - Unidades: de 1 a MAX_UNITS ids do condomínio informado, no escopo do usuário (fora = 404, ADR-002). Unidade
 *   excluída ou de outro condomínio = 422 e nada é gravado (tudo ou nada, numa transação).
 * - Auditoria: um update por unidade alterada, com antes e depois só dos campos que mudaram.
 */
final class UnitBulkSettings
{
    public const MAX_UNITS = 2000;
    public const SETTINGS = ['due_day', 'apportionment_exempt', 'postage_cents', 'postage_payer', 'reads_gas', 'reads_water', 'reads_power',
        'block_bill_merge', 'billing_responsible', 'bill_address', 'bill_email_to'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Valida as configurações (regra pura): só as chaves conhecidas; cada valor pela regra do campo do cadastro.
     * "due_day" vazio tira o dia padrão.
     *
     * @return array<string, mixed>
     */
    public static function settings(mixed $input): array
    {
        if (!is_array($input) || $input === []) {
            throw new RegistryValidationException(['settings' => 'Escolha pelo menos uma configuração para alterar.']);
        }
        $fields = Definitions::all()['units']['fields'];
        $clean = [];
        $errors = [];
        foreach ($input as $key => $value) {
            if ($key === 'partner_agency_id') {
                $id = $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $id === false ? $errors[$key] = 'Imobiliária inválida.' : $clean[$key] = $id;
                continue;
            }
            if (!in_array($key, self::SETTINGS, true)) {
                $errors[(string)$key] = 'Configuração desconhecida.';
                continue;
            }
            [$values, $fieldErrors] = RegistryValidator::validate(['fields' => [$key => $fields[$key]], 'status' => ['active' => ''], 'requires' => []], [$key => $value]);
            if (isset($fieldErrors[$key])) {
                $errors[$key] = $fields[$key]['label'] . ': ' . $fieldErrors[$key];
                continue;
            }
            $clean[$key] = $values[$key];
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }
        return $clean;
    }

    /**
     * @param array{condominium_id?:mixed, unit_ids?:mixed, settings?:mixed} $input
     * @return array{updated:int, unchanged:int}
     */
    public function apply(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null || !$this->scope->canAccess($user, (int)$condominium)) {
            throw new RegistryNotFound();
        }
        $ids = $input['unit_ids'] ?? null;
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map(static fn ($v): int => (int)filter_var($v, FILTER_VALIDATE_INT), $ids), static fn (int $v): bool => $v > 0))) : [];
        if ($ids === [] || count($ids) > self::MAX_UNITS) {
            throw new RegistryValidationException(['unit_ids' => 'Marque de 1 a ' . self::MAX_UNITS . ' unidades.']);
        }
        $settings = self::settings($input['settings'] ?? null);
        if (array_key_exists('partner_agency_id', $settings) && $settings['partner_agency_id'] !== null) {
            $agency = $this->pdo->prepare("SELECT 1 FROM erp_partner_agencies WHERE id = :id AND status = 'active'");
            $agency->execute(['id' => $settings['partner_agency_id']]);
            if (!$agency->fetchColumn()) {
                throw new RegistryValidationException(['partner_agency_id' => 'Imobiliária não encontrada ou inativa.']);
            }
        }
        $columns = array_keys($settings);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id, ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns))
                . ' FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) {
                throw new RegistryValidationException(['unit_ids' => 'Alguma unidade marcada não existe ou não é deste condomínio.']);
            }
            $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", $columns));
            $update = $this->pdo->prepare("UPDATE erp_units SET {$set}, updated_by = :u WHERE id = :id");
            $updated = 0;
            foreach ($rows as $row) {
                $before = [];
                $after = [];
                foreach ($settings as $column => $value) {
                    $old = $row[$column] === null ? null : (is_int($value) || is_bool($value) ? (int)$row[$column] : (string)$row[$column]);
                    if ($old !== $value) {
                        $before[$column] = $row[$column];
                        $after[$column] = $value;
                    }
                }
                if ($after === []) {
                    continue;
                }
                $update->execute($settings + ['u' => (int)$user->id, 'id' => (int)$row['id']]);
                Audit::record('update', 'erp_units', (int)$row['id'], $before, $after + ['_bulk' => count($ids)]);
                $updated++;
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['updated' => $updated, 'unchanged' => count($ids) - $updated];
    }

    /** Imobiliárias ativas para o seletor da configuração em massa. @return list<array{id:int,name:string}> */
    public function agencies(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
            $this->pdo->query("SELECT id, name FROM erp_partner_agencies WHERE status = 'active' ORDER BY name, id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC));
    }
}
