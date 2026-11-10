<?php

declare(strict_types=1);

namespace Source\Services\Help;

use PDO;

/**
 * Publica a base de conhecimento (arquivos) na Central de Ajuda (support_categories e
 * support_articles), ADR 0141.
 *
 * - Só mexe nas linhas com kb_key; matéria escrita no Help Desk nunca é alterada.
 * - Artigo que saiu da base vira rascunho (não apaga: guarda votos e visualizações).
 * - Artigo marcado "rascunho: sim" é gravado como rascunho.
 * - Tudo numa transação: ou a base inteira entra, ou nada muda.
 */
final class HelpSync
{
    public function __construct(private PDO $pdo, private KnowledgeBase $kb)
    {
    }

    /** @return array{categories:int,created:int,updated:int,unchanged:int,unpublished:int} */
    public function run(): array
    {
        $stats = ['categories' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0];
        $articles = $this->kb->articles();
        $categories = $this->kb->categories();

        $this->pdo->beginTransaction();
        try {
            $categoryIds = [];
            foreach ($categories as $key => $category) {
                $categoryIds[$key] = $this->upsertCategory($category);
                $stats['categories']++;
            }

            $urls = [];
            foreach ($articles as $slug => $article) {
                $urls[$slug] = '/suporte/' . self::categoryUri($article['category']) . '/' . $slug;
            }
            $renderer = new ArticleRenderer(
                static fn (string $slug): ?string => $urls[$slug] ?? null,
                fn (string $id): ?string => is_file($this->kb->shotsDirectory() . '/' . $id . '.jpg') ? KnowledgeBase::shotUrl($id) . '?v=' . substr((string)md5_file($this->kb->shotsDirectory() . '/' . $id . '.jpg'), 0, 8) : null
            );

            $seen = [];
            foreach ($articles as $slug => $article) {
                if (!isset($categoryIds[$article['category']])) {
                    continue;
                }
                $rendered = $renderer->render($article['body']);
                // Capa só quando o artigo pede (capa: id); a imagem de compartilhamento é o primeiro print.
                $cover = $article['cover'];
                $image = $article['screens'][0]['id'] ?? '';
                $meta = [
                    'codes' => $article['codes'],
                    'icon' => $article['icon'],
                    'route' => $article['route'],
                    'menu' => $article['menu'],
                    'situation' => $article['situation'],
                    'toc' => $rendered['toc'],
                    'related' => array_values(array_filter(array_map(static fn ($s) => isset($urls[$s]) ? ['title' => $articles[$s]['title'], 'url' => $urls[$s]] : null, $article['related']))),
                    'keywords' => $article['keywords'],
                    'cover' => $cover !== '' && is_file($this->kb->shotsDirectory() . '/' . $cover . '.jpg') ? KnowledgeBase::shotUrl($cover) : null,
                    'image' => $image !== '' && is_file($this->kb->shotsDirectory() . '/' . $image . '.jpg') ? KnowledgeBase::shotUrl($image) : null,
                    'file' => $article['file'],
                ];
                // As palavras-chave entram no fim do conteúdo, escondidas, para a busca achar sinônimos.
                $content = $rendered['html'];
                if ($article['keywords']) {
                    $content .= "\n" . '<p class="kb-keywords" hidden>' . ArticleRenderer::e(implode(', ', $article['keywords'])) . '</p>';
                }
                $status = $article['draft'] ? 'draft' : 'published';
                $hash = hash('sha256', json_encode([$article['title'], $article['summary'], $content, $meta, $status, $categoryIds[$article['category']]]));
                $key = 'kb:' . $slug;
                $seen[] = $key;

                $find = $this->pdo->prepare('SELECT id, kb_hash FROM support_articles WHERE kb_key = :key LIMIT 1');
                $find->execute(['key' => $key]);
                $current = $find->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    $clash = $this->pdo->prepare('SELECT id FROM support_articles WHERE uri = :uri AND kb_key IS NULL LIMIT 1');
                    $clash->execute(['uri' => $slug]);
                    if ($clash->fetchColumn()) {
                        throw new \RuntimeException("Já existe uma matéria do Help Desk com o endereço '{$slug}'. Renomeie o arquivo da base.");
                    }
                    $insert = $this->pdo->prepare('INSERT INTO support_articles (category_id, title, uri, summary, content, status, kb_key, kb_hash, kb_meta, published_at) VALUES (:category, :title, :uri, :summary, :content, :status, :key, :hash, :meta, NOW())');
                    $insert->execute(['category' => $categoryIds[$article['category']], 'title' => $article['title'], 'uri' => $slug, 'summary' => $article['summary'], 'content' => $content, 'status' => $status, 'key' => $key, 'hash' => $hash, 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    $stats['created']++;
                } elseif ($current['kb_hash'] !== $hash) {
                    $update = $this->pdo->prepare('UPDATE support_articles SET category_id = :category, title = :title, uri = :uri, summary = :summary, content = :content, status = :status, kb_hash = :hash, kb_meta = :meta, published_at = COALESCE(published_at, NOW()) WHERE id = :id');
                    $update->execute(['category' => $categoryIds[$article['category']], 'title' => $article['title'], 'uri' => $slug, 'summary' => $article['summary'], 'content' => $content, 'status' => $status, 'hash' => $hash, 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $current['id']]);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }

            $placeholders = $seen ? implode(',', array_fill(0, count($seen), '?')) : "''";
            $retire = $this->pdo->prepare("UPDATE support_articles SET status = 'draft' WHERE kb_key IS NOT NULL AND status <> 'draft' AND kb_key NOT IN ({$placeholders})");
            $retire->execute($seen);
            $stats['unpublished'] = $retire->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $stats;
    }

    /** Endereço da categoria da base na Central de Ajuda. Categoria do Help Desk com o mesmo endereço passa a ser da base (as matérias dela continuam). */
    public static function categoryUri(string $key): string
    {
        return $key;
    }

    private function upsertCategory(array $category): int
    {
        $find = $this->pdo->prepare('SELECT id FROM support_categories WHERE kb_key = :key OR (kb_key IS NULL AND uri = :uri) ORDER BY kb_key IS NULL LIMIT 1');
        $find->execute(['key' => $category['key'], 'uri' => self::categoryUri($category['key'])]);
        $id = $find->fetchColumn();
        $params = ['title' => $category['title'], 'uri' => self::categoryUri($category['key']), 'description' => mb_substr($category['description'], 0, 255), 'icon' => $category['icon'], 'position' => $category['position'], 'key' => $category['key']];
        if ($id) {
            $this->pdo->prepare("UPDATE support_categories SET title = :title, uri = :uri, description = :description, icon = :icon, position = :position, status = 'active', kb_key = :key WHERE id = :id")->execute($params + ['id' => $id]);
            return (int)$id;
        }
        $this->pdo->prepare("INSERT INTO support_categories (title, uri, description, icon, position, status, kb_key) VALUES (:title, :uri, :description, :icon, :position, 'active', :key)")->execute($params);
        return (int)$this->pdo->lastInsertId();
    }
}
