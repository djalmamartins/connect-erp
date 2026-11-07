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
}
