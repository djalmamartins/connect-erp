<?php

declare(strict_types=1);

namespace Source\Services;

/**
 * Normalização e validação do cadastro de condomínio (AP-004), sem acesso a banco.
 * A unicidade do CNPJ é verificada em CondominiumRegistry.
 */
final class CondominiumValidator
{
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
    public const STATUSES = ['active' => 'Ativo', 'inactive' => 'Inativo'];

    /** Campos aceitos do formulário, na ordem das colunas. */
    public const FIELDS = [
        'name', 'legal_name', 'document', 'status', 'zip_code', 'street', 'number', 'complement',
        'district', 'city', 'state', 'phone', 'email', 'blocks_count', 'units_count',
        'manager_person_id', 'contract_start_date', 'fee_due_day', 'bank_code', 'bank_branch', 'bank_account',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, string|int|null>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $value = static fn(string $key): string => trim((string)(is_scalar($input[$key] ?? null) ? $input[$key] : ''));
        $digits = static fn(string $key): string => preg_replace('/\D/', '', $value($key)) ?? '';
        $data = [];
        $errors = [];

        $data['name'] = $this->text($value('name'), 160, true, 3, 'name', 'Nome', $errors);
        $data['legal_name'] = $this->text($value('legal_name'), 160, true, 3, 'legal_name', 'Razão social', $errors);

        $data['document'] = $digits('document');
        if ($data['document'] === '') {
            $errors['document'] = 'Informe o CNPJ.';
        } elseif (!self::isValidCnpj($data['document'])) {
            $errors['document'] = 'CNPJ inválido.';
        }

        $data['status'] = $value('status') === '' ? 'active' : $value('status');
        if (!array_key_exists($data['status'], self::STATUSES)) {
            $errors['status'] = 'Situação inválida.';
        }

        $data['zip_code'] = $digits('zip_code');
        if (strlen($data['zip_code']) !== 8) {
            $errors['zip_code'] = 'Informe o CEP com 8 dígitos.';
        }
        $data['street'] = $this->text($value('street'), 160, true, 2, 'street', 'Logradouro', $errors);
        $data['number'] = $this->text($value('number'), 20, true, 1, 'number', 'Número', $errors);
        $data['complement'] = $this->text($value('complement'), 80, false, 0, 'complement', 'Complemento', $errors);
        $data['district'] = $this->text($value('district'), 100, true, 2, 'district', 'Bairro', $errors);
        $data['city'] = $this->text($value('city'), 100, true, 2, 'city', 'Cidade', $errors);
        $data['state'] = strtoupper($value('state'));
        if (!in_array($data['state'], self::STATES, true)) {
            $errors['state'] = 'Selecione a UF.';
        }

        $data['phone'] = $digits('phone') ?: null;
        if ($data['phone'] !== null && !in_array(strlen($data['phone']), [10, 11], true)) {
            $errors['phone'] = 'Telefone deve ter DDD e 8 ou 9 dígitos.';
        }
        $data['email'] = strtolower($value('email')) ?: null;
        if ($data['email'] !== null && (strlen($data['email']) > 190 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'E-mail inválido.';
        }

        $data['blocks_count'] = $this->integer($value('blocks_count'), 0, 999, 'blocks_count', 'Quantidade de blocos', $errors);
        $data['units_count'] = $this->integer($value('units_count'), 0, 100000, 'units_count', 'Quantidade de unidades', $errors);
        $data['manager_person_id'] = $this->integer($value('manager_person_id'), 1, PHP_INT_MAX, 'manager_person_id', 'Síndico', $errors);

        $data['contract_start_date'] = $value('contract_start_date') ?: null;
        if ($data['contract_start_date'] !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['contract_start_date']);
            if (!$date || $date->format('Y-m-d') !== $data['contract_start_date']) {
                $errors['contract_start_date'] = 'Data de início inválida.';
            }
        }
        $data['fee_due_day'] = $this->integer($value('fee_due_day'), 1, 28, 'fee_due_day', 'Dia de vencimento', $errors);

        $data['bank_code'] = $digits('bank_code') ?: null;
        if ($data['bank_code'] !== null && strlen($data['bank_code']) !== 3) {
            $errors['bank_code'] = 'Código do banco deve ter 3 dígitos.';
        }
        $data['bank_branch'] = strtoupper($value('bank_branch')) ?: null;
        if ($data['bank_branch'] !== null && !preg_match('/^\d{1,5}(-[\dX])?$/', $data['bank_branch'])) {
            $errors['bank_branch'] = 'Agência inválida (ex.: 1234 ou 1234-5).';
        }
        $data['bank_account'] = strtoupper($value('bank_account')) ?: null;
        if ($data['bank_account'] !== null && !preg_match('/^\d{1,15}(-[\dX])?$/', $data['bank_account'])) {
            $errors['bank_account'] = 'Conta inválida (ex.: 123456-7).';
        }
        $bankFilled = count(array_filter([$data['bank_code'], $data['bank_branch'], $data['bank_account']], static fn($v) => $v !== null));
        if ($bankFilled > 0 && $bankFilled < 3 && !isset($errors['bank_code']) && !isset($errors['bank_branch']) && !isset($errors['bank_account'])) {
            $errors['bank_code'] = 'Informe banco, agência e conta juntos.';
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** CNPJ com 14 dígitos, sem sequência repetida e com os dois dígitos verificadores corretos. */
    public static function isValidCnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $length) {
            $sum = 0;
            $weight = $length - 7;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int)$cnpj[$i] * $weight--;
                if ($weight < 2) {
                    $weight = 9;
                }
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int)$cnpj[$length] !== $digit) {
                return false;
            }
        }
        return true;
    }

    private function text(string $value, int $max, bool $required, int $min, string $key, string $label, array &$errors): ?string
    {
        if ($value === '') {
            if ($required) {
                $errors[$key] = "Preencha o campo {$label}.";
            }
            return $required ? '' : null;
        }
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $errors[$key] = "{$label} deve ter entre {$min} e {$max} caracteres.";
        }
        return $value;
    }

    private function integer(string $value, int $min, int $max, string $key, string $label, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            $errors[$key] = $max === PHP_INT_MAX ? "{$label} inválido." : "{$label} deve estar entre {$min} e {$max}.";
            return null;
        }
        return $int;
    }
}
