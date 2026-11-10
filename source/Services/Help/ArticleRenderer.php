<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Converte o corpo de um artigo da base de conhecimento em HTML seguro (ADR 0141).
 *
 * É um subconjunto pequeno de Markdown, escrito à mão de propósito: todo texto é escapado antes de
 * qualquer marcação, e só estas construções viram HTML:
 *
 *   ## Seção / ### Subseção         títulos (as seções formam o índice "Navegue pelo conteúdo")
 *   **negrito**, `código`           ênfase
 *   [[Financeiro > Faturas]]        caminho de menu em "chips"
 *   {{Salvar}}                      nome de botão destacado
 *   [texto](artigo:slug)            link para outro artigo; também https://... e /caminho
 *   - item / 1. item                listas
 *   > texto                         caixa de dica
 *   >! texto                        caixa de atenção
 *   >> [Tudo sobre X](artigo:slug)  link "Tudo sobre..." no fim da seção
 *   ```tela id ... ```              print da tela com marcações numeradas e legenda
 */
final class ArticleRenderer
{
    /** @var callable(string): ?string */
    private $articleUrl;

    /** @var callable(string): ?string */
    private $shotUrl;

    /**
     * @param callable(string): ?string $articleUrl slug -> endereço do artigo (null = inexistente)
     * @param callable(string): ?string|null $shotUrl id do print -> endereço da imagem (null = sem imagem)
     */
    public function __construct(callable $articleUrl, ?callable $shotUrl = null)
    {
        $this->articleUrl = $articleUrl;
        $this->shotUrl = $shotUrl ?? static fn (string $id): ?string => KnowledgeBase::shotUrl($id);
    }

    /**
     * @return array{html:string,toc:list<array{id:string,title:string}>,screens:list<string>}
     */
    public function render(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        $html = [];
        $toc = [];
        $screens = [];
        $paragraph = [];
        $list = null;
        $usedIds = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph) {
                $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $flushList = function () use (&$list, &$html): void {
            if ($list) {
                $tag = $list['type'];
                $html[] = "<{$tag}>" . implode('', array_map(fn ($item) => '<li>' . $this->inline($item) . '</li>', $list['items'])) . "</{$tag}>";
                $list = null;
            }
        };

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            $trim = trim($line);

            if (preg_match('/^```tela[ \t]+([a-z0-9-]+)/', $trim, $match)) {
                $flushParagraph();
                $flushList();
                $block = [];
                for ($i++; $i < $count && trim($lines[$i]) !== '```'; $i++) {
                    $block[] = $lines[$i];
                }
                $screen = KnowledgeBase::screens("```tela {$match[1]}\n" . implode("\n", $block) . "\n```")[0] ?? null;
                if ($screen) {
                    $screens[] = $screen['id'];
                    $html[] = $this->figure($screen);
                }
                continue;
            }
            if (str_starts_with($trim, '```')) {
                // Bloco de código comum: mostrado como texto, nunca interpretado.
                $flushParagraph();
                $flushList();
                $block = [];
                for ($i++; $i < $count && trim($lines[$i]) !== '```'; $i++) {
                    $block[] = $lines[$i];
                }
                $html[] = '<pre><code>' . self::e(implode("\n", $block)) . '</code></pre>';
                continue;
            }
            if ($trim === '') {
                $flushParagraph();
                $flushList();
                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.+)$/', $trim, $match)) {
                $flushParagraph();
                $flushList();
                $level = strlen($match[1]);
                $title = trim($match[2]);
                $id = self::anchor($title, $usedIds);
                if ($level === 2) {
                    $toc[] = ['id' => $id, 'title' => $title];
                }
                $html[] = "<h{$level} id=\"{$id}\">" . $this->inline($title) . "</h{$level}>";
                continue;
            }
            if (str_starts_with($trim, '>>')) {
                $flushParagraph();
                $flushList();
                $html[] = '<p class="kb-more">' . $this->inline(trim(substr($trim, 2))) . '</p>';
                continue;
            }
            if (str_starts_with($trim, '>')) {
                $flushParagraph();
                $flushList();
                $warning = str_starts_with($trim, '>!');
                $text = [trim(substr($trim, $warning ? 2 : 1))];
                while ($i + 1 < $count && preg_match('/^>(?![>!])\s?(.*)$/', trim($lines[$i + 1]), $next)) {
                    $text[] = trim($next[1]);
                    $i++;
                }
                $class = $warning ? 'kb-callout kb-callout--warning' : 'kb-callout';
                $html[] = '<aside class="' . $class . '"><p>' . $this->inline(implode(' ', $text)) . '</p></aside>';
                continue;
            }
            if (preg_match('/^(?:[-*]|(\d+)\.)\s+(.+)$/', $trim, $match)) {
                $flushParagraph();
                $type = $match[1] !== '' ? 'ol' : 'ul';
                if ($list && $list['type'] !== $type) {
                    $flushList();
                }
                $list ??= ['type' => $type, 'items' => []];
                $list['items'][] = $match[2];
                continue;
            }
            if ($list && preg_match('/^\s{2,}\S/', $line)) {
                $list['items'][count($list['items']) - 1] .= ' ' . $trim;
                continue;
            }
            $flushList();
            $paragraph[] = $trim;
        }
        $flushParagraph();
        $flushList();

        return ['html' => implode("\n", $html), 'toc' => $toc, 'screens' => $screens];
    }

    /** Texto de uma linha: escapa tudo e só depois aplica as marcações permitidas. */
    public function inline(string $text): string
    {
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . self::e($m[1]) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text) ?? $text;

        $out = self::e($text);

        $out = preg_replace_callback('/\[\[(.+?)\]\]/', static function ($m) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:&gt;|›|→)\s*/u', $m[1]) ?: []), static fn ($p) => $p !== ''));
            return '<span class="kb-path">' . implode('<i aria-hidden="true">›</i>', array_map(static fn ($p) => '<span>' . $p . '</span>', $parts)) . '</span>';
        }, $out) ?? $out;
        $out = preg_replace('/\{\{(.+?)\}\}/', '<span class="kb-button">$1</span>', $out) ?? $out;
        $out = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $out) ?? $out;
        $out = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($href, 'artigo:')) {
                $url = ($this->articleUrl)(substr($href, 7));
                return $url === null ? $m[1] : '<a href="' . self::e($url) . '">' . $m[1] . '</a>';
            }
            if (preg_match('#^(https://|/(?!/))#', $href)) {
                $external = str_starts_with($href, 'https://');
                return '<a href="' . self::e($href) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
            }
            return $m[1];
        }, $out) ?? $out;

        return preg_replace_callback("/\x01(\d+)\x01/", static fn ($m) => $codes[(int)$m[1]] ?? '', $out) ?? $out;
    }

    private function figure(array $screen): string
    {
        $url = ($this->shotUrl)($screen['id']);
        $caption = $screen['caption'] !== '' ? $screen['caption'] : 'Tela do sistema';
        $legend = '';
        if ($screen['marks']) {
            $items = '';
            foreach ($screen['marks'] as $mark) {
                if ($mark['text'] === '') {
                    continue;
                }
                $items .= '<li><b>' . (int)$mark['n'] . '</b><span>' . $this->inline($mark['text']) . '</span></li>';
            }
            $legend = $items !== '' ? '<ol class="kb-shot__legend">' . $items . '</ol>' : '';
        }
        $image = $url === null
            ? '<div class="kb-shot__pending">Print em preparação</div>'
            : '<a class="kb-shot__zoom" href="' . self::e($url) . '" target="_blank" rel="noopener"><img src="' . self::e($url) . '" alt="' . self::e($caption) . '" loading="lazy" width="1440" height="900"></a>';

        return '<figure class="kb-shot" id="tela-' . self::e($screen['id']) . '">' . $image
            . '<figcaption>' . $this->inline($caption) . '</figcaption>' . $legend . '</figure>';
    }

    /** @param array<string, true> $used */
    private static function anchor(string $title, array &$used): string
    {
        $plain = preg_replace('/[\[\]{}*`>]/', '', $title) ?? $title;
        $ascii = strtolower((string)(function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain) : $plain));
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', $ascii), '-') ?: 'secao';
        $base = $id;
        for ($n = 2; isset($used[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        $used[$id] = true;
        return $id;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
