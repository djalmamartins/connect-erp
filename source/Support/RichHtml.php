<?php

namespace Source\Support;

/**
 * Limpa o HTML rico escrito no Studio (blog, páginas, destaques e matérias da Central de Ajuda)
 * antes de ele aparecer no site público (ADR 0346).
 *
 * Fica só uma lista fechada de marcações de texto, links, imagens e tabelas. Somem scripts,
 * estilos, formulários, eventos (on*), links javascript:/data: e qualquer atributo fora da lista.
 * Vídeo incorporado só de YouTube ou Vimeo. O texto das marcações removidas continua, escapado.
 */
final class RichHtml
{
    /** Marcações aceitas e os atributos de cada uma (além de class, id, title e aria-*). */
    private const TAGS = [
        'a' => ['href', 'target', 'rel'], 'abbr' => [], 'aside' => [], 'b' => [], 'blockquote' => ['cite'],
        'br' => [], 'caption' => [], 'code' => [], 'col' => ['span'], 'colgroup' => ['span'], 'dd' => [],
        'del' => [], 'details' => ['open'], 'div' => [], 'dl' => [], 'dt' => [], 'em' => [], 'figcaption' => [],
        'figure' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [],
        'i' => [], 'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'], 'ins' => [], 'kbd' => [], 'li' => ['value'],
        'mark' => [], 'ol' => ['start', 'type', 'reversed'], 'p' => [], 'pre' => [], 'q' => ['cite'], 's' => [],
        'section' => [], 'small' => [], 'span' => [], 'strong' => [], 'sub' => [], 'summary' => [], 'sup' => [],
        'table' => [], 'tbody' => [], 'td' => ['colspan', 'rowspan'], 'tfoot' => [], 'th' => ['colspan', 'rowspan', 'scope'],
        'thead' => [], 'tr' => [], 'u' => [], 'ul' => [],
    ];

    /** Marcações apagadas junto com o conteúdo (o texto delas não serve como texto). */
    private const DROP = ['script', 'style', 'template', 'noscript', 'object', 'embed', 'applet', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base', 'frame', 'frameset', 'textarea', 'select', 'option'];

    private const COMMON = ['class', 'id', 'title', 'hidden', 'lang', 'dir'];
    private const STYLE = ['text-align', 'color', 'background-color', 'font-weight', 'font-style', 'text-decoration', 'width', 'height', 'max-width', 'vertical-align'];
    private const URL_ATTRS = ['href', 'src', 'cite'];
    private const VIDEO_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'];

    public static function clean(?string $html): string
    {
        $html = (string)$html;
        if (trim($html) === '') {
            return '';
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="rich-html-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('rich-html-root');
        if (!$root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }
        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!isset(self::TAGS[$tag])) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::attributes($child, $tag);
            if ($tag === 'iframe' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }

    private static function attributes(\DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::COMMON, self::TAGS[$tag]);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string)$attribute->nodeValue;
            $keep = in_array($name, $allowed, true) || str_starts_with($name, 'aria-') || ($name === 'style');
            if ($keep && in_array($name, self::URL_ATTRS, true)) {
                $keep = $tag === 'iframe' ? self::videoUrl($value) : self::safeUrl($value, $tag === 'img');
            }
            if ($keep && $name === 'style') {
                $value = self::style($value);
                $keep = $value !== '';
            }
            $element->removeAttribute($attribute->nodeName);
            if ($keep) {
                $element->setAttribute($name, $value);
            }
        }
        if ($tag === 'a' && strtolower($element->getAttribute('target')) === '_blank' && !preg_match('/\bnoopener\b/i', $element->getAttribute('rel'))) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'iframe') {
            $element->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
            $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        }
    }

    /** Links e imagens: http(s), mailto, tel, âncora ou endereço relativo; nada de javascript:, data:, vbscript:. */
    public static function safeUrl(string $url, bool $image = false): bool
    {
        $plain = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if ($plain === '') {
            return false;
        }
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $plain, $match)) {
            return true;
        }
        $scheme = strtolower($match[1]);
        return $image ? in_array($scheme, ['http', 'https'], true) : in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private static function videoUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        return ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), self::VIDEO_HOSTS, true);
    }

    private static function style(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $rule) {
            $pair = explode(':', $rule, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $property = strtolower(trim($pair[0]));
            $value = trim($pair[1]);
            if (!in_array($property, self::STYLE, true) || !preg_match('/^[#a-zA-Z0-9.,%() \-]+$/', $value) || preg_match('/url|expression|javascript|var\(/i', $value)) {
                continue;
            }
            $kept[] = $property . ': ' . $value;
        }
        return implode('; ', $kept);
    }
}
