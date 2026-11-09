<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

/**
 * Texto simples (ADR 0096): campo marcado com 'plain' => true no cadastro não aceita HTML nem link.
 * Usado nos banners do aplicativo, que aparecem para todos os moradores. A saída continua escapada na
 * view; esta regra impede que o conteúdo carregue marcação ou endereço já na gravação.
 */
final class PlainText
{
    public const MESSAGE = 'Use só texto: sem HTML, sem link e sem endereço de site.';

    /** Problema (regra pura) ou null. */
    public static function problem(string $text): ?string
    {
        $patterns = [
            '/[<>]/u',                                   // marcação HTML
            '/&(#\d+|#x[0-9a-f]+|[a-z]+);/iu',           // entidades HTML
            '/\b(https?|ftp|javascript|data|mailto|tel):/iu', // esquemas de link
            '/\bwww\./iu',
            '/\b[a-z0-9-]+(\.[a-z0-9-]+)*\.(com|net|org|br|app|io|me|info|biz|xyz|online|site|link)\b(\/|\s|$)/iu', // domínio solto
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::MESSAGE;
            }
        }
        return null;
    }
}
