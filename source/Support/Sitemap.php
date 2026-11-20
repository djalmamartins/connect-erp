<?php

namespace Source\Support;

use Source\Models\Post\Category;
use Source\Models\Post\Post;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;

/**
 * Mapa do site para o Google (sitemap.xml) com as páginas públicas, os artigos publicados e a
 * Central de Ajuda (ADR 0399). Só entra o que já é público: rascunho, lixeira e post agendado ficam fora.
 */
class Sitemap
{
    /** Páginas fixas do site, na ordem do menu. */
    public const STATIC_PATHS = ["/", "/solicite-sua-proposta", "/artigos", "/suporte", "/politica-de-privacidade", "/termos-de-uso"];

    /** @var list<array{loc: string, lastmod: ?string}> */
    private array $entries = [];

    public function add(string $loc, ?string $lastmod = null): self
    {
        foreach ($this->entries as $entry) {
            if ($entry["loc"] === $loc) {
                return $this;
            }
        }
        $time = $lastmod ? strtotime($lastmod) : false;
        $this->entries[] = ["loc" => $loc, "lastmod" => $time ? date("Y-m-d", $time) : null];
        return $this;
    }

    /** @return list<array{loc: string, lastmod: ?string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Monta o mapa com o conteúdo publicado no banco. Uma falha ao ler uma parte (tabela ausente numa
     * instalação nova) não derruba o mapa: as outras partes continuam.
     */
    public static function fromDatabase(): self
    {
        $map = new self();
        foreach (self::STATIC_PATHS as $path) {
            $map->add(url($path));
        }

        try {
            $posts = (new Post())->findPost(null, null, "uri, category, post_at, updated_at")->order("post_at DESC")->fetch(true) ?: [];
            $categoryIds = [];
            foreach ($posts as $post) {
                $map->add(url("/artigos/{$post->uri}"), $post->updated_at ?: $post->post_at);
                if (!empty($post->category)) {
                    $categoryIds[(int)$post->category] = true;
                }
            }
            if ($categoryIds) {
                $ids = implode(",", array_map("intval", array_keys($categoryIds)));
                $categories = (new Category())->find("id IN ({$ids}) AND uri <> ''", null, "uri, updated_at")->fetch(true) ?: [];
                foreach ($categories as $category) {
                    $map->add(url("/artigos/em/{$category->uri}"), $category->updated_at);
                }
            }
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, "web", ["event_type" => "sitemap_posts_failed"]);
        }

        try {
            $sections = [];
            foreach ((new SupportCategory())->find("status = :s", "s=active", "id, uri, updated_at")->fetch(true) ?: [] as $section) {
                $sections[(int)$section->id] = $section->uri;
                $map->add(url("/suporte/{$section->uri}"), $section->updated_at);
            }
            foreach ((new SupportArticle())->find("status = 'published' AND published_at <= NOW()", null, "category_id, uri, updated_at")->fetch(true) ?: [] as $article) {
                if (isset($sections[(int)$article->category_id])) {
                    $map->add(url("/suporte/{$sections[(int)$article->category_id]}/{$article->uri}"), $article->updated_at);
                }
            }
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, "web", ["event_type" => "sitemap_support_failed"]);
        }

        return $map;
    }

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->entries as $entry) {
            $xml .= "  <url><loc>" . htmlspecialchars($entry["loc"], ENT_XML1 | ENT_QUOTES, "UTF-8") . "</loc>";
            if ($entry["lastmod"]) {
                $xml .= "<lastmod>{$entry["lastmod"]}</lastmod>";
            }
            $xml .= "</url>\n";
        }
        return $xml . "</urlset>\n";
    }
}
