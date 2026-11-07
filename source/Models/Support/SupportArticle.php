<?php

namespace Source\Models\Support;

use Source\Core\Model;

class SupportArticle extends Model
{
    public function __construct()
    {
        parent::__construct("support_articles", ["id"], ["category_id", "title", "uri", "summary", "content", "status"]);
    }

    public function findPublished(?string $terms = null, ?string $params = null)
    {
        return $this->find("status = 'published' AND published_at <= NOW()" . ($terms ? " AND {$terms}" : ""), $params);
    }

    public function findByUri(string $uri, bool $publishedOnly = false): ?self
    {
        $terms = "uri = :uri" . ($publishedOnly ? " AND status = 'published' AND published_at <= NOW()" : "");
        return $this->find($terms, "uri={$uri}")->fetch();
    }

    public function category(): ?SupportCategory
    {
        return (new SupportCategory())->findById((int)$this->category_id);
    }
}
