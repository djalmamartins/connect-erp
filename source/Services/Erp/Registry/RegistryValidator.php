<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use DateTimeImmutable;
use Source\Support\Erp\BrDocument;

/**
 * Normaliza e valida a entrada de um cadastro conforme a definição (Definitions).
 * Puro (sem banco): escopo por condomínio, unicidade e regras de "um ativo" ficam no
 * RegistryService. Campos desconhecidos são ignorados (lista de permissão).
 */
final class RegistryValidator
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, string>} [valores normalizados, erros por campo]
     */
    public static function validate(array $definition, array $input): array
    {
        $clean = [];
        $errors = [];
        foreach ($definition['fields'] as $name => $field) {
            [$value, $error] = self::field($field, $input[$name] ?? null);
            $clean[$name] = $value;
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }

        $status = (string)($input['status'] ?? 'active');
        if (!array_key_exists($status, $definition['status'])) {
            $errors['status'] = 'Situação inválida.';
        }
        $clean['status'] = $status;

        foreach ($definition['fields'] as $name => $field) {
            $after = $field['after'] ?? null;
            if ($after !== null && !isset($errors[$name]) && $clean[$name] !== null && $clean[$after] !== null && $clean[$name] < $clean[$after]) {
                $errors[$name] = sprintf('Deve ser igual ou posterior a "%s".', mb_strtolower($definition['fields'][$after]['label']));
            }
        }
        foreach ($definition['requires'] as $rule) {
            $flag = (bool)($clean[$rule['when'] ?? $rule['unless']] ?? false);
            $needed = isset($rule['when']) ? $flag : !$flag;
            if ($needed && ($clean[$rule['field']] ?? null) === null && !isset($errors[$rule['field']])) {
                $errors[$rule['field']] = $rule['message'];
            }
        }
        return [$clean, $errors];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: mixed, 1: ?string}
     */
    private static function field(array $field, mixed $raw): array
    {
        $type = $field['type'];
        if ($type === 'bool') {
            return [filter_var($raw, FILTER_VALIDATE_BOOL) ? 1 : 0, null];
        }
        if (is_array($raw) || is_object($raw)) {
            return [null, 'Valor inválido.'];
        }
        $text = trim((string)($raw ?? ''));
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($text === '') {
            return [null, $field['required'] ? 'Campo obrigatório.' : null];
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return [null, 'Texto inválido.'];
        }

        switch ($type) {
            case 'condominium':
                $id = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                return $id === false ? [null, 'Selecione um condomínio.'] : [$id, null];
            case 'unit':
                // Existência, escopo e condomínio da unidade são conferidos no RegistryService.
                $id = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                return $id === false ? [null, 'Selecione uma unidade.'] : [$id, null];
            case 'decimal':
                $scale = (int)($field['scale'] ?? 2);
                $normalized = str_replace([' ', ','], ['', '.'], $text);
                if (!preg_match('/^\d+(\.\d{1,' . $scale . '})?$/', $normalized)) {
                    return [null, "Número inválido (até {$scale} casas decimais)."];
                }
                $number = round((float)$normalized, $scale);
                $min = (float)($field['min'] ?? 0);
                $max = (float)($field['max_value'] ?? 1e12);
                if ($number < $min || $number > $max) {
                    return [null, 'Informe um valor entre ' . self::number($min, $scale) . ' e ' . self::number($max, $scale) . '.'];
                }
                return [$number, null];
            case 'select':
                return array_key_exists($text, $field['options']) ? [$text, null] : [null, 'Opção inválida.'];
            case 'cpf':
                return BrDocument::isCpf($text) ? [BrDocument::digits($text), null] : [null, 'CPF inválido.'];
            case 'cnpj':
                return BrDocument::isCnpj($text) ? [BrDocument::digits($text), null] : [null, 'CNPJ inválido.'];
            case 'document':
                $digits = BrDocument::digits($text);
                if (strlen($digits) === 11) {
                    return BrDocument::isCpf($digits) ? [$digits, null] : [null, 'CPF inválido.'];
                }
                if (strlen($digits) === 14) {
                    return BrDocument::isCnpj($digits) ? [$digits, null] : [null, 'CNPJ inválido.'];
                }
                return [null, 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos).'];
            case 'id_document':
                $digits = BrDocument::digits($text);
                if (strlen($digits) === 11 && preg_match('/^[\d.\-\s]+$/', $text)) {
                    return BrDocument::isCpf($digits) ? [$digits, null] : [null, 'CPF inválido.'];
                }
                $normalized = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $text));
                return preg_match('/^[0-9A-Z]{5,20}$/', $normalized) ? [$normalized, null] : [null, 'Documento inválido.'];
            case 'email':
                $email = mb_strtolower($text);
                return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email, null] : [null, 'E-mail inválido.'];
            case 'phone':
                $digits = BrDocument::digits($text);
                return preg_match('/^(55)?\d{10,11}$/', $digits) || preg_match('/^(0800|0300)\d{7}$/', $digits) || preg_match('/^\d{3,5}$/', $digits)
                    ? [$digits, null]
                    : [null, 'Telefone inválido. Use DDD e número.'];
            case 'date':
                $date = null;
                foreach (['Y-m-d', 'd/m/Y'] as $format) {
                    $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text);
                    if ($parsed !== false && $parsed->format($format) === $text) {
                        $date = $parsed;
                        break;
                    }
                }
                if ($date === null) {
                    return [null, 'Data inválida.'];
                }
                $year = (int)$date->format('Y');
                return $year < 1900 || $year > 2100 ? [null, 'Data fora do intervalo aceito.'] : [$date->format('Y-m-d'), null];
            case 'money':
                $normalized = str_replace(['R$', ' '], '', $text);
                if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', $normalized)) {
                    $normalized = str_replace(['.', ','], ['', '.'], $normalized);
                } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
                    return [null, 'Valor inválido. Ex.: 1.250,00'];
                }
                $cents = (int)round((float)$normalized * 100);
                return $cents > 99_999_999_999 ? [null, 'Valor muito alto.'] : [$cents, null];
            case 'percent':
                $normalized = str_replace([',', '%', ' '], ['.', '', ''], $text);
                if (!is_numeric($normalized) || (float)$normalized < 0 || (float)$normalized > 100) {
                    return [null, 'Informe um percentual entre 0 e 100.'];
                }
                return [round((float)$normalized, 2), null];
            case 'plate':
                $plate = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $text));
                return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $plate) ? [$plate, null] : [null, 'Placa inválida. Ex.: ABC1D23 ou ABC1234.'];
            case 'oab':
                if (!preg_match('/^([A-Za-z]{2})\s*[-\/]?\s*(\d{1,7})\s*([A-Za-z]?)$/', $text, $m)) {
                    return [null, 'OAB inválida. Use UF e número, ex.: SP 123456.'];
                }
                $uf = strtoupper($m[1]);
                if (!in_array($uf, self::UF, true)) {
                    return [null, 'UF da OAB inválida.'];
                }
                return [$uf . ' ' . ltrim($m[2], '0') . strtoupper($m[3]), null];
            case 'longtext':
            case 'text':
            default:
                $max = (int)($field['max'] ?? ($type === 'longtext' ? 2000 : 160));
                if (mb_strlen($text) > $max) {
                    return [null, "Máximo de {$max} caracteres."];
                }
                if ($type === 'text') {
                    $text = (string)preg_replace('/\s+/u', ' ', $text);
                }
                if (isset($field['pattern']) && !preg_match($field['pattern'], $text)) {
                    return [null, $field['pattern_message'] ?? 'Formato inválido.'];
                }
                return [$text, null];
        }
    }

    private static function number(float $value, int $scale): string
    {
        $text = number_format($value, $scale, ',', '.');
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    private const UF = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
}
