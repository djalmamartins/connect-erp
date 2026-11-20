<?php

namespace Source\Support;

/**
 * Textos de mensagem escritos no Moves Editor com a barra "basic" (ADR 0610): chamados, notas, respostas
 * rápidas, comunicados e notificações. Antes eram só texto; os registros antigos continuam texto.
 *
 * - store(): o que vai para o banco (HTML limpo pela lista curta do RichHtml, ou o texto como veio);
 * - html(): para mostrar numa tela ou e-mail em HTML (texto antigo vira parágrafos escapados);
 * - plain(): para WhatsApp, push, app de celular, resumo de e-mail e busca (sem marcação nenhuma).
 */
final class RichText
{
    public static function isHtml(?string $value): bool
    {
        return (bool)preg_match('~</?(p|br|div|strong|b|em|i|u|s|del|a|ul|ol|li|blockquote|pre|code|span|sub|sup|h[1-6])\b[^>]*>~i', (string)$value);
    }

    public static function store(?string $value): string
    {
        $value = trim((string)$value);
        if (!self::isHtml($value)) {
            return trim(strip_tags($value));
        }
        $clean = trim(RichHtml::clean($value, true));
        return self::plain($clean) === '' ? '' : $clean;
    }

    public static function html(?string $value): string
    {
        $value = (string)$value;
        if (!self::isHtml($value)) {
            return nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
        return RichHtml::clean($value, true);
    }

    public static function plain(?string $value): string
    {
        $value = (string)$value;
        if (!self::isHtml($value)) {
            return trim($value);
        }
        // Espaços e quebras dentro do HTML não contam (como no navegador), menos dentro de <pre>.
        $text = stripos($value, '<pre') === false ? (preg_replace('~\s+~u', ' ', $value) ?? $value) : $value;
        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = preg_replace('~<li\b[^>]*>~i', "\n• ", $text) ?? $text;
        $text = preg_replace('~</(p|div|ul|ol|blockquote|pre|h[1-6])>~i', "\n", $text) ?? $text;
        $text = preg_replace('~<a\b[^>]*href=(["\'])(https?://[^"\']+)\1[^>]*>(.*?)</a>~is', '$3 ($2)', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("~[ \t]*\n[ \t]*~", "\n", $text) ?? $text;
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
