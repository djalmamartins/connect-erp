<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

/**
 * Texto simples (ADRs 0096 e 0097): campo marcado com 'plain' => true no cadastro não aceita HTML nem link.
 * Usado nos banners do aplicativo, que aparecem para todos os moradores. A saída continua escapada na
 * view; esta regra impede que o conteúdo carregue marcação ou endereço já na gravação.
 *
 * Recusa:
 * - marcação e entidades HTML;
 * - esquemas de link (http, https, ftp, javascript, data, mailto, tel) e "//" no início de um endereço;
 * - "www.";
 * - domínio solto: pelo menos um rótulo antes de um TLD só de letras (2 a 24), quando o TLD é um dos comuns
 *   (COMMON_TLDS) ou o domínio termina em ".com.br" ou ".net.br" etc.
 *
 * Não recusa (ADR 0097): "Sr.João", "R$1.500", "Bloco A.2", "obs.: trazer RG", "10.000,00". O rótulo só
 * casa com letras e números ASCII; letra acentuada logo depois corta o TLD, e rótulo só de números não é TLD.
 */
final class PlainText
{
    public const MESSAGE = 'Use só texto: sem HTML, sem link e sem endereço de site.';
    public const COMMON_TLDS = ['com', 'net', 'org', 'br', 'io', 'info', 'app', 'xyz', 'top', 'online', 'site', 'link', 'me', 'co', 'ly', 'gl'];

    /** Problema (regra pura) ou null. */
    public static function problem(string $text): ?string
    {
        $patterns = [
            '/[<>]/u',                                                       // marcação HTML
            '/&(#\d+|#x[0-9a-f]+|[a-z]+);/iu',                               // entidades HTML
            '/\b(https?|ftp|javascript|data|mailto|tel):/iu',                // esquemas de link
            '/(^|[\s(\[\'"])\/\/[a-z0-9]/iu',                                // endereço sem esquema: //exemplo
            '/\bwww\./iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::MESSAGE;
            }
        }
        return self::looseDomain($text) ? self::MESSAGE : null;
    }

    /**
     * Domínio solto (regra pura): rótulos ASCII separados por ponto, terminando num TLD só de letras (2 a 24)
     * que não é seguido de letra (acentuada ou não), dígito ou hífen. Recusa quando o TLD é comum ou o
     * domínio termina em ".com.br" (e afins: ".net.br", ".org.br").
     */
    public static function looseDomain(string $text): bool
    {
        if (preg_match_all('/(?<![\p{L}\p{N}.\-])((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+)([a-z]{2,24})(?![\p{L}\p{N}\-])/iu', $text, $matches, PREG_SET_ORDER) === 0) {
            return false;
        }
        foreach ($matches as $m) {
            $labels = array_values(array_filter(explode('.', rtrim($m[1], '.')), static fn (string $l): bool => $l !== ''));
            $tld = strtolower($m[2]);
            if ($labels === [] || array_filter($labels, static fn (string $l): bool => !ctype_digit($l)) === []) {
                continue;   // rótulos só de números: "10.000", "1.500"
            }
            $full = strtolower($m[1] . $m[2]);
            if (in_array($tld, self::COMMON_TLDS, true) || preg_match('/\.(com|net|org)\.br$/', $full) === 1) {
                return true;
            }
        }
        return false;
    }
}
