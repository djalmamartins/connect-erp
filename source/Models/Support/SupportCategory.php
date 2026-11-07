<?php

namespace Source\Models\Support;

use Source\Core\Model;

class SupportCategory extends Model
{
    public function __construct()
    {
        parent::__construct("support_categories", ["id"], ["title", "uri", "icon", "status"]);
    }

    public function findByUri(string $uri): ?self
    {
        return $this->find("uri = :uri", "uri={$uri}")->fetch();
    }

    public function articles(bool $publishedOnly = false)
    {
        $terms = "category_id = :category" . ($publishedOnly ? " AND status = 'published' AND published_at <= NOW()" : "");
        return (new SupportArticle())->find($terms, "category={$this->id}");
    }
}
