<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Base de conhecimento do Connect ERP (ADR 0141).
 *
 * Os artigos ficam em arquivos (container/web/support/v1/kb/artigos/<categoria>/<slug>.md), um por
 * página ou funcionalidade, versionados junto com o código. Este serviço lê, valida e renderiza os
 * arquivos; HelpSync grava o resultado em support_articles para a Central de Ajuda pública
 * (/suporte) e para o Help Desk. Os prints marcados são gerados por tools/help/shots.mjs a partir
 * dos blocos ```tela dos próprios artigos.
 */
final class KnowledgeBase
{
    public const SITUATIONS = ['funciona', 'exemplo', 'sem-backend', 'guia'];

    private string $root;

    /** @var array<string, array>|null */
    private ?array $articles = null;

    /** @var array<string, array>|null */
    private ?array $categories = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? self::defaultRoot(), '/');
    }

    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 3) . '/container/web/support/v1/kb';
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Pasta pública dos prints gerados (container/web/support/v1/assets/kb). */
    public function shotsDirectory(): string
    {
        return dirname($this->root) . '/assets/kb';
    }

    /** Endereço público de um print, relativo à raiz do site. */
    public static function shotUrl(string $id): string
    {
        return '/container/web/support/v1/assets/kb/' . $id . '.jpg';
    }

    /** @return array<string, array{key:string,title:string,description:string,icon:string,position:int}> */
    public function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }
        $file = $this->root . '/categorias.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('categorias.json ausente ou inválido em ' . $this->root);
        }
        $categories = [];
        foreach ($data as $position => $item) {
            $key = (string)($item['chave'] ?? '');
            if (!preg_match('/^[a-z0-9-]{2,60}$/', $key)) {
                throw new \RuntimeException('Categoria com chave inválida: ' . $key);
            }
            $categories[$key] = [
                'key' => $key,
                'title' => (string)($item['titulo'] ?? $key),
                'description' => (string)($item['descricao'] ?? ''),
                'icon' => (string)($item['icone'] ?? 'help-circle-outline'),
                'position' => (int)$position + 1,
            ];
        }
        return $this->categories = $categories;
    }

    /**
     * Todos os artigos, indexados pelo slug.
     *
     * @return array<string, array>
     */
    public function articles(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }
        $articles = [];
        $files = glob($this->root . '/artigos/*/*.md') ?: [];
        sort($files);
        foreach ($files as $file) {
            $article = self::parse((string)file_get_contents($file));
            $article['slug'] = basename($file, '.md');
            $article['folder'] = basename(dirname($file));
            $article['file'] = substr($file, strlen(dirname(__DIR__, 3)) + 1);
            $articles[$article['slug']] = $article;
        }
        return $this->articles = $articles;
    }

    public function article(string $slug): ?array
    {
        return $this->articles()[$slug] ?? null;
    }

    /** @return array<string, array> artigos indexados pelo código da página (AP-004, CN-002...). */
    public function byCode(): array
    {
        $map = [];
        foreach ($this->articles() as $article) {
            foreach ($article['codes'] as $code) {
                $map[$code] = $article;
            }
        }
        return $map;
    }

    /**
     * Separa o cabeçalho (chave: valor entre linhas ---) do corpo e extrai os blocos ```tela.
     */
    public static function parse(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $meta = [];
        $body = $source;
        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $source, $match)) {
            foreach (explode("\n", $match[1]) as $line) {
                if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                    continue;
                }
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $meta[strtolower(trim($pair[0]))] = trim($pair[1]);
                }
            }
            $body = $match[2];
        }
        $list = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));

        return [
            'codes' => $list(strtoupper($meta['codigo'] ?? '')),
            'title' => $meta['titulo'] ?? '',
            'summary' => $meta['resumo'] ?? '',
            'category' => $meta['categoria'] ?? '',
            'route' => $meta['rota'] ?? '',
            'menu' => $meta['menu'] ?? '',
            'icon' => $meta['icone'] ?? 'document-text-outline',
            'situation' => $meta['situacao'] ?? 'funciona',
            'related' => $list($meta['relacionados'] ?? ''),
            'keywords' => $list($meta['palavras'] ?? ''),
            'draft' => in_array(strtolower($meta['rascunho'] ?? 'nao'), ['sim', 'true', '1'], true),
            'cover' => $meta['capa'] ?? '',
            'body' => $body,
            'screens' => self::screens($body),
            'meta' => $meta,
        ];
    }

    /**
     * Blocos de print: ```tela <id> ... ```. Cada linha é "chave: valor"; marcas numeradas usam
     * "1: <seletor> | <texto>"; ações antes do print usam "antes: clicar <seletor>".
     *
     * @return list<array{id:string,route:string,caption:string,marks:list<array{n:int,selector:string,text:string}>,before:list<string>,frame:string,options:array}>
     */
    public static function screens(string $body): array
    {
        $screens = [];
        if (!preg_match_all('/^```tela[ \t]+([a-z0-9-]+)[ \t]*\n(.*?)^```[ \t]*$/ms', $body, $blocks, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($blocks as $block) {
            $screen = ['id' => $block[1], 'route' => '', 'caption' => '', 'marks' => [], 'before' => [], 'frame' => 'janela', 'options' => []];
            foreach (explode("\n", $block[2]) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pair = explode(':', $line, 2);
                if (count($pair) !== 2) {
                    continue;
                }
                [$key, $value] = [strtolower(trim($pair[0])), trim($pair[1])];
                if (ctype_digit($key)) {
                    $parts = array_map('trim', explode('|', $value, 2));
                    $screen['marks'][] = ['n' => (int)$key, 'selector' => $parts[0], 'text' => $parts[1] ?? ''];
                } elseif ($key === 'rota') {
                    $screen['route'] = $value;
                } elseif ($key === 'legenda') {
                    $screen['caption'] = $value;
                } elseif ($key === 'antes') {
                    $screen['before'][] = $value;
                } elseif ($key === 'recorte') {
                    $screen['frame'] = $value;
                } else {
                    $screen['options'][$key] = $value;
                }
            }
            $screens[] = $screen;
        }
        return $screens;
    }

    /**
     * Problemas que impedem publicar a base. A ferramenta tools/help.php check (e o CI) falham com
     * qualquer item desta lista.
     *
     * @param list<string> $requiredCodes códigos de página que precisam ter artigo (catálogo do ERP)
     * @return list<string>
     */
    public function problems(array $requiredCodes = [], bool $requireShots = true): array
    {
        $problems = [];
        try {
            $categories = $this->categories();
        } catch (\RuntimeException $exception) {
            return [$exception->getMessage()];
        }
        $articles = $this->articles();
        $screenIds = [];
        foreach ($articles as $slug => $article) {
            $where = $article['file'];
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $problems[] = "{$where}: nome do arquivo deve ser um slug (letras minúsculas, números e hífens)";
            }
            if (mb_strlen($article['title']) < 4 || mb_strlen($article['title']) > 180) {
                $problems[] = "{$where}: titulo ausente ou maior que 180 caracteres";
            }
            if (mb_strlen($article['summary']) < 20 || mb_strlen($article['summary']) > 320) {
                $problems[] = "{$where}: resumo deve ter entre 20 e 320 caracteres";
            }
            if (!isset($categories[$article['category']])) {
                $problems[] = "{$where}: categoria '{$article['category']}' não existe em categorias.json";
            } elseif ($article['category'] !== $article['folder']) {
                $problems[] = "{$where}: o arquivo deve ficar na pasta da categoria '{$article['category']}'";
            }
            if (!in_array($article['situation'], self::SITUATIONS, true)) {
                $problems[] = "{$where}: situacao deve ser " . implode(', ', self::SITUATIONS);
            }
            if ($article['route'] !== '' && !preg_match('#^/[A-Za-z0-9/_{}\-]*$#', $article['route'])) {
                $problems[] = "{$where}: rota inválida";
            }
            foreach ($article['related'] as $related) {
                if (!isset($articles[$related])) {
                    $problems[] = "{$where}: relacionado '{$related}' não existe";
                }
            }
            if (preg_match_all('/\(artigo:([a-z0-9-]+)\)/', $article['body'], $links)) {
                foreach (array_unique($links[1]) as $link) {
                    if (!isset($articles[$link])) {
                        $problems[] = "{$where}: link para artigo inexistente '{$link}'";
                    }
                }
            }
            if (mb_strlen(trim(strip_tags($article['body']))) < 120) {
                $problems[] = "{$where}: corpo do artigo muito curto (mínimo de 120 caracteres)";
            }
            foreach ($article['screens'] as $screen) {
                if (isset($screenIds[$screen['id']])) {
                    $problems[] = "{$where}: print '{$screen['id']}' repetido (também em {$screenIds[$screen['id']]})";
                }
                $screenIds[$screen['id']] = $where;
                if ($screen['route'] === '' || !str_starts_with($screen['route'], '/')) {
                    $problems[] = "{$where}: print '{$screen['id']}' sem rota";
                }
                if ($requireShots && !is_file($this->shotsDirectory() . '/' . $screen['id'] . '.jpg')) {
                    $problems[] = "{$where}: falta gerar o print '{$screen['id']}' (node tools/help/shots.mjs --only {$screen['id']})";
                }
            }
        }
        $covered = $this->byCode();
        foreach ($requiredCodes as $code) {
            if (!isset($covered[$code])) {
                $problems[] = "página {$code} do menu sem artigo na base de conhecimento (php tools/help.php scaffold)";
            }
        }
        return $problems;
    }

    /** Códigos das páginas do menu do ERP (catálogo gerado de docs/specs/routes.json). */
    public static function erpCatalog(): array
    {
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        return $catalog;
    }

    /** Assinatura do conteúdo: muda quando qualquer arquivo da base muda. */
    public function hash(): string
    {
        $files = array_merge([$this->root . '/categorias.json'], glob($this->root . '/artigos/*/*.md') ?: []);
        sort($files);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, basename($file) . "\0" . (string)@file_get_contents($file));
        }
        return hash_final($context);
    }
}
