<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Explicação de cada tela do ERP para quem usa o sistema pela primeira vez (ADR 0309).
 *
 * Uma fonte só para dois lugares:
 *  - o quadro "Sobre esta tela", no topo de cada página do ERP (components/layout/page-intro.php):
 *    o que a tela faz e o que acontece por trás quando você grava, com o link para o guia completo;
 *  - a seção "O que acontece por trás" do artigo da Central de Ajuda, escrita no .md por
 *    `php tools/help.php explicar` e conferida pelo `check` (o artigo não pode ficar diferente).
 *
 * Os textos ficam em guides/<área>.php, um arquivo por área do menu, no formato
 *   'AP-005' => ['faz' => '...', 'por_tras' => ['...', '...'], 'mais' => "markdown só do artigo"].
 * Linguagem simples e só o que o sistema faz de verdade (conferido no código de cada tela).
 */
final class PageGuides
{
    public const SECTION = 'O que acontece por trás';

    /** @var array<string, array{faz:string,por_tras:list<string>,mais:string}>|null */
    private static ?array $all = null;

    /** @return array<string, array{faz:string,por_tras:list<string>,mais:string}> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        $files = glob(__DIR__ . '/guides/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = require $file;
            foreach (is_array($data) ? $data : [] as $code => $guide) {
                $all[strtoupper((string)$code)] = [
                    'faz' => trim((string)($guide['faz'] ?? '')),
                    'por_tras' => array_values(array_filter(array_map(static fn ($item): string => trim((string)$item), (array)($guide['por_tras'] ?? [])), static fn (string $item): bool => $item !== '')),
                    'mais' => trim((string)($guide['mais'] ?? '')),
                ];
            }
        }
        return self::$all = $all;
    }

    /** @return array{faz:string,por_tras:list<string>,mais:string}|null */
    public static function for(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        return self::all()[strtoupper($code)] ?? null;
    }

    /** Seção do artigo, em markdown, a partir da explicação da tela. */
    public static function section(array $guide): string
    {
        $out = ['## ' . self::SECTION, ''];
        if ($guide['por_tras'] !== []) {
            $out[] = 'Quando você usa esta tela, o sistema faz o seguinte:';
            $out[] = '';
            foreach ($guide['por_tras'] as $item) {
                $out[] = '- ' . $item;
            }
            $out[] = '';
        }
        if ($guide['mais'] !== '') {
            $out[] = $guide['mais'];
            $out[] = '';
        }
        return implode("\n", $out);
    }

    /**
     * Escreve (ou reescreve) a seção "O que acontece por trás" no texto do artigo: no lugar da que já
     * existe ou, na primeira vez, antes de "Quem pode usar" (ou no fim).
     */
    public static function apply(string $source, array $guide): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $section = self::section($guide);
        $heading = '## ' . self::SECTION;
        $lines = explode("\n", $source);
        $start = null;
        $end = count($lines);
        $fence = false;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '```')) {
                $fence = !$fence;
                continue;
            }
            if ($fence) {
                continue;
            }
            if ($start === null && rtrim($line) === $heading) {
                $start = $index;
                continue;
            }
            if ($start !== null && str_starts_with($line, '## ')) {
                $end = $index;
                break;
            }
        }
        if ($start === null) {
            $before = null;
            $fence = false;
            foreach ($lines as $index => $line) {
                if (str_starts_with($line, '```')) {
                    $fence = !$fence;
                    continue;
                }
                if (!$fence && rtrim($line) === '## Quem pode usar') {
                    $before = $index;
                    break;
                }
            }
            if ($before === null) {
                return rtrim($source, "\n") . "\n\n" . rtrim($section, "\n") . "\n";
            }
            $start = $end = $before;
        }
        $head = rtrim(implode("\n", array_slice($lines, 0, $start)), "\n");
        $tail = ltrim(implode("\n", array_slice($lines, $end)), "\n");
        return $head . "\n\n" . rtrim($section, "\n") . "\n" . ($tail !== '' ? "\n" . rtrim($tail, "\n") . "\n" : '');
    }

    /** A explicação da primeira página do artigo que tem uma. */
    public static function forArticle(array $article): ?array
    {
        foreach ($article['codes'] ?? [] as $code) {
            if (($guide = self::for($code)) !== null) {
                return $guide;
            }
        }
        return null;
    }

    /**
     * Problemas para o `php tools/help.php check`: explicação de página que não existe no menu, texto
     * curto ou longo demais e artigo cuja seção "O que acontece por trás" está diferente da explicação.
     *
     * @param list<string> $codes páginas do menu
     * @return list<string>
     */
    public static function problems(KnowledgeBase $kb, array $codes): array
    {
        $problems = [];
        foreach (self::all() as $code => $guide) {
            if (!in_array($code, $codes, true)) {
                $problems[] = "explicação da tela {$code}: a página não existe no menu do ERP";
            }
            $length = mb_strlen($guide['faz']);
            if ($length < 30 || $length > 420) {
                $problems[] = "explicação da tela {$code}: 'faz' deve ter entre 30 e 420 caracteres (tem {$length})";
            }
            if ($guide['por_tras'] === []) {
                $problems[] = "explicação da tela {$code}: falta 'por_tras'";
            }
        }
        foreach ($kb->articles() as $article) {
            $guide = self::forArticle($article);
            if ($guide === null) {
                continue;
            }
            $file = dirname(__DIR__, 3) . '/' . $article['file'];
            $source = is_file($file) ? (string)file_get_contents($file) : '';
            if ($source !== '' && self::apply($source, $guide) !== str_replace(["\r\n", "\r"], "\n", $source)) {
                $problems[] = "{$article['file']}: a seção \"" . self::SECTION . "\" está diferente da explicação da tela (php tools/help.php explicar)";
            }
        }
        return $problems;
    }

    /** Quantas páginas do menu já têm explicação. */
    public static function coverage(array $codes): int
    {
        return count(array_intersect($codes, array_keys(self::all())));
    }
}
