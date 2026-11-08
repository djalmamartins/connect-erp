<?php

declare(strict_types=1);

namespace Source\Services\Erp;

/**
 * Dados de demonstração do Connect ERP v1 (somente leitura, nunca gravados no banco).
 *
 * Os conjuntos ficam em database/fixtures/erp-demo/{nome}.php, separados do código de
 * produção. O modo é ligado por ERP_DEMO_DATA (true/false); sem a variável, fica ligado
 * fora de produção (MOVESOS_ENV != production) e desligado em produção. Desligado, as
 * páginas recebem conjuntos vazios e mostram o estado vazio honesto.
 */
final class DemoData
{
    private const DIRECTORY = __DIR__ . '/../../../database/fixtures/erp-demo';

    public static function enabled(): bool
    {
        $flag = getenv('ERP_DEMO_DATA');
        if ($flag !== false && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOL);
        }
        return (getenv('MOVESOS_ENV') ?: 'production') !== 'production';
    }

    /** @return array<string, mixed> */
    public static function get(string $set): array
    {
        if (!self::enabled() || !preg_match('/^[a-z0-9-]+$/', $set)) {
            return [];
        }
        $file = self::DIRECTORY . '/' . $set . '.php';
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /**
     * Linhas fictícias e determinísticas para uma tabela, a partir das colunas
     * "Rótulo:tipo" da definição da página. Mesma página, mesmos dados.
     *
     * @param list<string> $columns
     * @return list<list<array{0: string, 1: string}>> pares [texto exibido, valor de ordenação]
     */
    public static function rows(string $seed, array $columns, int $count = 23): array
    {
        $vocabulary = self::get('vocabulary');
        if ($vocabulary === []) {
            return [];
        }
        mt_srand(crc32($seed));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($columns as $column) {
                [$label, $type] = array_pad(explode(':', $column, 2), 2, 'text');
                $row[] = self::value($label, $type, $vocabulary, $i);
            }
            $rows[] = $row;
        }
        mt_srand();
        return $rows;
    }

    /** @return array{0: string, 1: string} */
    private static function value(string $label, string $type, array $v, int $i): array
    {
        $pick = static fn (array $list): string => $list[mt_rand(0, count($list) - 1)];
        $options = static fn (string $t): array => preg_match('/\((.*)\)/', $t, $m) ? explode('|', $m[1]) : [];
        $base = strtok($type, '(');
        switch ($base) {
            case 'condo':
                $value = $pick($v['condos']);
                return [$value, $value];
            case 'unit':
                $unit = sprintf('%d%02d', mt_rand(1, 18), mt_rand(1, 4)) . (mt_rand(0, 2) ? '' : '-' . chr(65 + mt_rand(0, 2)));
                return [$unit, $unit];
            case 'person':
                $value = $pick($v['first']) . ' ' . $pick($v['last']);
                return [$value, $value];
            case 'doc':
                $digits = '';
                for ($d = 0; $d < 11; $d++) {
                    $digits .= mt_rand(0, 9);
                }
                $value = str_contains(mb_strtolower($label), 'cnpj') ? substr($digits, 0, 2) . '.***.***/0001-' . substr($digits, 9, 2) : '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
                return [$value, $digits];
            case 'phone':
                $value = sprintf('(19) 9%04d-%04d', mt_rand(1000, 9999), mt_rand(0, 9999));
                return [$value, $value];
            case 'email':
                $value = strtolower($pick($v['first'])) . '.' . mt_rand(10, 99) . '@example.invalid';
                return [$value, $value];
            case 'date':
                $ts = strtotime('2026-11-07') + mt_rand(-75, 45) * 86400;
                return [date('d/m/Y', $ts), (string)$ts];
            case 'month':
                $ts = strtotime('2026-11-01 -' . mt_rand(0, 11) . ' months');
                return [self::month((int)date('n', $ts)) . '/' . date('Y', $ts), date('Ym', $ts)];
            case 'money':
                $cents = mt_rand(8000, 4800000);
                return ['R$ ' . number_format($cents / 100, 2, ',', '.'), (string)$cents];
            case 'int':
                $n = str_contains(mb_strtolower($label), 'unidade') ? mt_rand(24, 240) : (str_contains(mb_strtolower($label), 'nº') || str_contains(mb_strtolower($label), 'número') || str_contains(mb_strtolower($label), 'nosso') ? 10000 + $i * 37 + mt_rand(0, 30) : mt_rand(1, 60));
                return [number_format($n, 0, ',', '.'), (string)$n];
            case 'pct':
                $p = mt_rand(5, 1500) / 100;
                return [number_format($p, 2, ',', '.') . '%', (string)$p];
            case 'bank':
                $value = $pick($v['banks']);
                return [$value, $value];
            case 'select':
            case 'status':
                $list = $options($type) ?: ['—'];
                $value = $pick($list);
                return [$value, $value];
            default:
                $l = mb_strtolower($label);
                if (str_contains($l, 'fornecedor') || str_contains($l, 'favorecido') || str_contains($l, 'escritório') || str_contains($l, 'seguradora') || str_contains($l, 'emitente') || str_contains($l, 'imobiliária')) {
                    $value = $pick($v['companies']);
                } elseif (str_contains($l, 'cidade')) {
                    $value = $pick($v['cities']) . '/SP';
                } elseif (str_contains($l, 'área') || str_contains($l, 'item') || str_contains($l, 'assunto')) {
                    $value = $pick($v['texts']);
                } elseif (str_contains($l, 'placa')) {
                    $value = chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . mt_rand(1, 9) . chr(65 + mt_rand(0, 9)) . mt_rand(10, 99);
                } else {
                    $value = $pick($v['descriptions']);
                }
                return [$value, $value];
        }
    }

    private static function month(int $n): string
    {
        return ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][$n - 1];
    }
}
