<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Support\Audit;

/**
 * Troca de inquilino ou proprietário da unidade (revisão de 15/11, etapa 2, ADR 0311; opções do modelo 6).
 *
 * - Pessoa nova: uma já cadastrada no escopo (person_id, de GET /api/v1/people/search) ou o cadastro rápido (person:
 *   nome, CPF/CNPJ, e-mail, telefone), com as regras do cadastro de pessoas (inclusive mesmo documento = mesmo nome).
 * - Com "registrar histórico" (padrão): os vínculos atuais do papel terminam na data de saída e o novo começa na data
 *   de entrada; sem histórico, os atuais só ficam inativos e o novo entra sem datas.
 * - Faturas: só quando o papel trocado é quem paga a unidade (responsável pela cobrança; sem definição, o
 *   proprietário; com responsável financeiro, ninguém muda). Opções: current_and_next (competência do mês da entrada
 *   em diante), next_only (meses seguintes), all_unbilled (todas em aberto), none. Conta com boleto ativo nunca muda
 *   de pagador. As contas que ficam (todas, com none) passam a ter o antigo como pagador gravado.
 * - Tudo numa transação, com auditoria de cada vínculo e um update na unidade com o resumo. Fora do escopo = 404.
 */
final class UnitOccupantChange
{
    public const ROLES = ['tenant' => 'inquilino', 'owner' => 'proprietário'];
    public const INVOICE_OPTIONS = [
        'current_and_next' => 'Alterar a fatura do mês atual e dos meses seguintes',
        'next_only' => 'Alterar apenas as faturas dos meses seguintes',
        'all_unbilled' => 'Alterar todas as faturas sem boleto gerado (passadas e futuras, inclusive de meses fechados)',
        'none' => 'Não alterar',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Valida o pedido (regra pura): papel, opção das faturas, datas e a pessoa (id ou cadastro rápido).
     * @return array{role:string, invoices:string, history:bool, exit_on:?string, entry_on:?string, person_id:?int, person:?array<string, mixed>}
     */
    public static function input(array $input): array
    {
        $errors = [];
        $role = is_string($input['role'] ?? null) && isset(self::ROLES[$input['role']]) ? $input['role'] : null;
        if ($role === null) {
            $errors['role'] = 'Escolha se a troca é do inquilino ou do proprietário.';
        }
        $invoices = is_string($input['invoices'] ?? null) && isset(self::INVOICE_OPTIONS[$input['invoices']]) ? $input['invoices'] : null;
        if ($invoices === null) {
            $errors['invoices'] = 'Escolha o que fazer com as faturas abertas.';
        }
        $history = !array_key_exists('history', $input) || filter_var($input['history'], FILTER_VALIDATE_BOOL);
        $date = static function (mixed $value): ?string {
            $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
        };
        $exit = $date($input['exit_on'] ?? null);
        $entry = $date($input['entry_on'] ?? null);
        if ($history) {
            if ($exit === null) {
                $errors['exit_on'] = 'Informe a data de saída (AAAA-MM-DD).';
            }
            if ($entry === null) {
                $errors['entry_on'] = 'Informe a data de entrada (AAAA-MM-DD).';
            }
            if ($exit !== null && $entry !== null && $entry < $exit) {
                $errors['entry_on'] = 'A entrada do novo não pode ser antes da saída do antigo.';
            }
        }
        $personId = filter_var($input['person_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $person = null;
        if ($personId === false || $personId === null) {
            $personId = null;
            if (!is_array($input['person'] ?? null)) {
                $errors['person_id'] = 'Escolha a pessoa ou cadastre uma nova.';
            } else {
                $fields = Definitions::all()['residents']['fields'];
                $definition = ['fields' => ['name' => $fields['name'], 'document' => $fields['document'], 'email' => $fields['email'], 'phone' => $fields['phone']],
                    'status' => ['active' => ''], 'requires' => []];
                $raw = array_intersect_key($input['person'], $definition['fields']) + ['name' => null, 'document' => null, 'email' => null, 'phone' => null];
                [$values, $fieldErrors] = RegistryValidator::validate($definition, $raw);
                foreach ($fieldErrors as $key => $message) {
                    $errors['person.' . $key] = ($fields[$key]['label'] ?? $key) . ': ' . $message;
                }
                $person = array_intersect_key($values, $definition['fields']);
            }
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }
        return ['role' => (string)$role, 'invoices' => (string)$invoices, 'history' => $history, 'exit_on' => $history ? $exit : null,
            'entry_on' => $history ? $entry : null, 'person_id' => $personId, 'person' => $person];
    }

    /**
     * Pessoas atuais da unidade por papel, para a tela.
     * @return array{owner:list<array{id:int,name:string,starts_on:?string}>, tenant:list<array{id:int,name:string,starts_on:?string}>, payer_role:?string}
     */
    public function current(User $user, int $unitId): array
    {
        $unit = $this->unit($user, $unitId, false);
        $out = ['owner' => [], 'tenant' => [], 'payer_role' => $this->payerRole($unit)];
        foreach ($this->links($unitId, ['owner', 'tenant']) as $row) {
            $out[$row['relationship']][] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'starts_on' => $row['starts_on'] !== null ? (string)$row['starts_on'] : null];
        }
        return $out;
    }

    /**
     * @return array{resident_id:int, ended:list<int>, invoices:string, moved:int, kept:int, with_boleto:int}
     */
    public function apply(User $user, int $unitId, array $input, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $request = self::input($input);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $unit = $this->unit($user, $unitId, true);
            $person = $this->person($user, $request);
            $old = $this->links($unitId, [$request['role']]);
            $document = (string)($person['document'] ?? '');
            foreach ($old as $row) {
                $same = $document !== '' ? (string)preg_replace('/\D/', '', (string)$row['document']) === $document
                    : ($row['document'] === null && RegistryService::personKey((string)$row['name']) === RegistryService::personKey((string)$person['name']));
                if ($same) {
                    throw new RegistryValidationException(['person_id' => 'Essa pessoa já é o ' . self::ROLES[$request['role']] . ' desta unidade.']);
                }
            }
            $this->assertSamePerson($person);

            $ended = [];
            $end = $this->pdo->prepare('UPDATE erp_residents SET ends_on = :e, status = :s, updated_by = :u WHERE id = :id');
            foreach ($old as $row) {
                $status = $request['history'] && $request['exit_on'] > $today->format('Y-m-d') ? 'active' : 'inactive';
                $endsOn = $request['history'] ? $request['exit_on'] : $row['ends_on'];
                $end->execute(['e' => $endsOn, 's' => $status, 'u' => (int)$user->id, 'id' => (int)$row['id']]);
                Audit::record('update', 'erp_residents', (int)$row['id'], ['ends_on' => $row['ends_on'], 'status' => $row['status']],
                    ['ends_on' => $endsOn, 'status' => $status, '_occupant_change' => $request['role']]);
                $ended[] = (int)$row['id'];
            }
            $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email, phone, starts_on, status, created_by, updated_by)
                VALUES (:c, :u, :l, :r, :n, :d, :e, :p, :s, \'active\', :by, :by2)')->execute([
                    'c' => (int)$unit['condominium_id'], 'u' => $unitId, 'l' => mb_substr((string)$unit['label'], 0, 40), 'r' => $request['role'],
                    'n' => $person['name'], 'd' => $document !== '' ? $document : null, 'e' => $person['email'] ?? null, 'p' => $person['phone'] ?? null,
                    's' => $request['entry_on'], 'by' => (int)$user->id, 'by2' => (int)$user->id]);
            $newId = (int)$this->pdo->lastInsertId();
            Audit::record('create', 'erp_residents', $newId, null, ['unit_id' => $unitId, 'relationship' => $request['role'], 'starts_on' => $request['entry_on'], '_occupant_change' => true]);

            $invoices = ['invoices' => 'not_payer', 'moved' => 0, 'kept' => 0, 'with_boleto' => 0];
            if ($this->payerRole($unit) === $request['role']) {
                $invoices = ['invoices' => $request['invoices']] + $this->moveInvoices($unitId, $request, $old[0]['id'] ?? null, $newId, $request['entry_on'] ?? $today->format('Y-m-d'));
            }
            Audit::record('update', 'erp_units', $unitId, ['_' . $request['role'] => $ended], ['_' . $request['role'] => [$newId], '_history' => $request['history'], '_invoices' => $invoices]);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['resident_id' => $newId, 'ended' => $ended] + $invoices;
    }

    /** @return array{moved:int, kept:int, with_boleto:int} */
    private function moveInvoices(int $unitId, array $request, int|string|null $oldId, int $newId, string $since): array
    {
        $stmt = $this->pdo->prepare("SELECT e.id, e.competence, e.payer_resident_id,
                EXISTS(SELECT 1 FROM erp_bank_charges b WHERE b.entry_id = e.id AND b.status IN ('pending','registered','paid')) AS has_boleto
            FROM erp_entries e WHERE e.unit_id = ? AND e.nature = 'receita' AND e.status = 'active' AND e.paid_on IS NULL AND e.deleted_at IS NULL FOR UPDATE");
        $stmt->execute([$unitId]);
        $month = substr($since, 0, 7);
        $move = [];
        $keep = [];
        $boleto = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['has_boleto'] === 1) {
                $boleto++;
                continue;
            }
            $chosen = match ($request['invoices']) {
                'current_and_next' => (string)$row['competence'] >= $month,
                'next_only' => (string)$row['competence'] > $month,
                'all_unbilled' => true,
                default => false,
            };
            if ($chosen) {
                $move[] = (int)$row['id'];
            } elseif ($row['payer_resident_id'] === null && $oldId !== null) {
                $keep[] = (int)$row['id'];
            }
        }
        $set = function (array $ids, int $payer): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->pdo->prepare('UPDATE erp_entries SET payer_resident_id = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                    ->execute(array_merge([$payer], $chunk));
            }
        };
        $set($move, $newId);
        if ($oldId !== null) {
            $set($keep, (int)$oldId);
        }
        return ['moved' => count($move), 'kept' => count($keep), 'with_boleto' => $boleto];
    }

    /** Papel que paga a unidade: responsável pela cobrança; sem definição, o proprietário; com responsável financeiro, nenhum. */
    private function payerRole(array $unit): ?string
    {
        $financial = $this->pdo->prepare("SELECT 1 FROM erp_residents WHERE unit_id = ? AND relationship = 'financial' AND status = 'active' AND deleted_at IS NULL
            AND (ends_on IS NULL OR ends_on >= CURDATE()) LIMIT 1");
        $financial->execute([(int)$unit['id']]);
        if ($financial->fetchColumn()) {
            return null;
        }
        return ($unit['billing_responsible'] ?? 'nao_definido') === 'inquilino' ? 'tenant' : 'owner';
    }

    private function unit(User $user, int $unitId, bool $lock): array
    {
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, label, billing_responsible FROM erp_units WHERE id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($unit === false || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $unit;
    }

    /** @return list<array<string, mixed>> vínculos ativos e valendo hoje */
    private function links(int $unitId, array $roles): array
    {
        $stmt = $this->pdo->prepare("SELECT id, relationship, name, document, starts_on, ends_on, status FROM erp_residents
            WHERE unit_id = ? AND relationship IN ('" . implode("','", $roles) . "') AND status = 'active' AND deleted_at IS NULL
              AND (ends_on IS NULL OR ends_on >= CURDATE()) ORDER BY id");
        $stmt->execute([$unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{name:string, document:?string, email:?string, phone:?string} */
    private function person(User $user, array $request): array
    {
        if ($request['person_id'] === null) {
            return $request['person'];
        }
        $stmt = $this->pdo->prepare('SELECT condominium_id, name, document, email, phone FROM erp_residents WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$request['person_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && !$this->scope->canAccess($user, (int)$row['condominium_id']) && (string)$row['document'] !== '') {
            // A busca devolve o menor id da pessoa, que pode ser de outro condomínio: usa um vínculo dela no escopo.
            $allowed = array_map('intval', $this->scope->allowedIds($user));
            $same = $this->pdo->prepare('SELECT condominium_id, name, document, email, phone FROM erp_residents WHERE document = ? AND deleted_at IS NULL
                AND condominium_id IN (' . ($allowed === [] ? '0' : implode(',', $allowed)) . ') ORDER BY id LIMIT 1');
            $same->execute([(string)$row['document']]);
            $row = $same->fetch(PDO::FETCH_ASSOC);
        }
        if ($row === false || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        $document = (string)preg_replace('/\D/', '', (string)$row['document']);
        return ['name' => (string)$row['name'], 'document' => $document !== '' ? $document : null, 'email' => $row['email'], 'phone' => $row['phone']];
    }

    /** Mesmo CPF/CNPJ = mesmo nome (a regra same_person do cadastro, ADR 0311). */
    private function assertSamePerson(array $person): void
    {
        $document = (string)($person['document'] ?? '');
        if ($document === '') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT name FROM erp_residents WHERE document = ? AND deleted_at IS NULL LIMIT 50');
        $stmt->execute([$document]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (RegistryService::personKey((string)$name) !== RegistryService::personKey((string)$person['name'])) {
                throw new RegistryValidationException(['person.document' => Definitions::all()['residents']['same_person']['message']]);
            }
        }
    }
}
