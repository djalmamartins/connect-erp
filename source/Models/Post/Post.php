<?php

namespace Source\Models\Post;

use Source\Core\Model;
use Source\Models\User;


/**
 * ERP | Class Post
 *
 * @author Djalma Martins
 * @package Source\Models\Post
 */
class Post extends Model
{
    /**
     * Post constructor.
     */
    public function __construct()
    {
        parent::__construct("posts", ["id"], ["title", "uri", "subtitle", "content"]);
    }

    /**
     * @param null|string $terms
     * @param null|string $params
     * @param string $columns
     * @return mixed|Model
     */
    public function findPost(?string $terms = null, ?string $params = null, string $columns = "*")
    {
        $terms = "status = :status AND post_at <= NOW()" . ($terms ? " AND {$terms}" : "");
        $params = "status=post" . ($params ? "&{$params}" : "");

        return parent::find($terms, $params, $columns);
    }


    /**
     * @param string $uri
     * @param string $columns
     * @return Post|null
     */
    public function findByUri(string $uri, string $columns = "*"): ?Post
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
     * @return null|Category
     */
    public function category(): ?Category
    {
        if (!$this->category) {
            return null;
        }
        // Lista de posts no site: cada post pedia a mesma categoria de novo (N+1). Na web, uma leitura por categoria
        // por requisição (ADR 0611); no terminal e nos testes, sempre do banco.
        if (PHP_SAPI === 'cli') {
            return (new Category())->findById($this->category);
        }
        static $categories = [];
        $id = (int)$this->category;
        if (!array_key_exists($id, $categories)) {
            $categories[$id] = (new Category())->findById($id);
        }
        return $categories[$id];
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $checkUri = (new Post())->find("uri = :uri AND id != :id", "uri={$this->uri}&id={$this->id}");

        if ($checkUri->count()) {
            $this->uri = "{$this->uri}-{$this->lastId()}";
        }

        return parent::save();
    }
}