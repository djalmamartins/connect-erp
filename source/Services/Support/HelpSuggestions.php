<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Services\Environments;

/**
 * Artigos da Central de Ajuda (CMS, ADR 0141) sugeridos dentro do ERP e do Help Desk (ADR 0153).
 *
 * Mesma regra da busca pública (/suporte/buscar): só publicados, cada palavra (2+ letras, até 6)
 * precisa estar no título, no resumo ou no texto, título primeiro e depois os mais vistos. O link
 * aponta para o artigo no site, que é público.
 */
final class HelpSuggestions
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return list<string> palavras válidas da busca */
    public static function terms(string $query): array
    {
        $query = mb_substr(trim(strip_tags($query)), 0, 120);
        $words = array_filter(preg_split('/[\s,.;:!?()"\']+/u', $query) ?: [], static fn (string $word): bool => mb_strlen($word) >= 2);
        // Palavras longas primeiro: "boleto" ajuda mais que "de".
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        return array_slice(array_values(array_unique(array_map('mb_strtolower', $words))), 0, 6);
    }

    /**
     * Artigos para a busca. $any = true pede qualquer uma das palavras (sugestão enquanto a
     * pessoa digita o assunto do chamado); false pede todas (busca do Help Desk).
     *
     * @return list<array{id:int,title:string,summary:string,category:string,url:string}>
     */
    public function search(string $query, int $limit = 5, bool $any = false): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($terms as $i => $term) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $where[] = "(a.title LIKE :t{$i} OR a.summary LIKE :s{$i} OR a.content LIKE :c{$i})";
            $params["t{$i}"] = $params["s{$i}"] = $params["c{$i}"] = $like;
        }
        $rank = [];
        foreach ($terms as $i => $term) {
            $rank[] = "(a.title LIKE :r{$i})";
            $params["r{$i}"] = '%' . addcslashes($term, '%_\\') . '%';
        }
        $sql = "SELECT a.id, a.title, a.summary, a.uri, c.uri AS category_uri, c.title AS category_title
            FROM support_articles a JOIN support_categories c ON c.id = a.category_id
            WHERE a.status = 'published' AND a.published_at <= NOW() AND c.status = 'active'
              AND (" . implode($any ? ' OR ' : ' AND ', $where) . ")
            ORDER BY (" . implode(' + ', $rank) . ") DESC, a.views DESC, a.title
            LIMIT " . max(1, min(20, $limit));
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'summary' => (string)($row['summary'] ?? ''),
            'category' => (string)$row['category_title'],
            'url' => Environments::url('site', '/suporte/' . $row['category_uri'] . '/' . $row['uri']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
