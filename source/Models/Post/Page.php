<?php

namespace Source\Models\Post;

use Source\Core\Model;
use Source\Models\User;
class Page extends Model
{

    public function __construct()
    {
        parent::__construct("pages", ["id"], ["title", "uri", "content"]);
    }

    public function findPage(?string $terms = null, ?string $params = null, string $columns = "*")
    {
        $terms = "status = :status AND post_at <= NOW()" . ($terms ? " AND {$terms}" : "");
        $params = "status=post" . ($params ? "&{$params}" : "");

        return parent::find($terms, $params, $columns);
    }

    public function findByUri(string $uri, string $columns = "*"): ?Page
    {
        $find = $this->find("uri = :uri", "uri={$uri}", $columns);
        return $find->fetch();
    }

    /**
     * @return null|User
     */
    public function author(): ?User
    {
        if ($this->author) {
            return (new User())->findById($this->author);
        }
        return null;
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $checkUri = (new Page())->find("uri = :uri AND id != :id", "uri={$this->uri}&id={$this->id}");
        if ($checkUri->count()) {
            $this->uri = "{$this->uri}-{$this->lastId()}";
        }
        return parent::save();
    }
}
