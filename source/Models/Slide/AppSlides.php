<?php

namespace Source\Models\Slide;

use Source\Core\Model;
use Source\Models\User;


/**
 * Class Slide
 * @package Source\Models
 */
class AppSlides extends Model
{

    /**
     * Slide constructor.
     */
    public function __construct()
    {
        parent::__construct("slides", ["id"], ["title", "uri", "content"]);
    }


    /**
     * @param string|null $terms
     * @param string|null $params
     * @param string $columns
     * @return mixed|Model
     */
    public function findSlide(?string $terms = null, ?string $params = null, string $columns = "*")
    {
        $terms = "status = :status AND post_at <= NOW()" . ($terms ? " AND {$terms}" : "");
        $params = "status=post" . ($params ? "&{$params}" : "");

        return parent::find($terms, $params, $columns);
    }

    /**
     * @param string $uri
     * @param string $columns
     * @return Slide|null
     */
    public function findByUri(string $uri, string $columns = "*"): ?AppSlides
    {
        $find = $this->find("uri = :uri", "uri={$uri}", $columns);
        return $find->fetch();
    }

    /**
     * @return User|null
     */
    public function author(): ?User
    {
        if ($this->author) {
            return (new User())->findById($this->author);
        }
        return null;
    }

    /**
     * @return CategorySlide|null
     */
    public function categoryslide(): ?CategorySlide
    {
        if ($this->category) {
            return (new CategorySlide())->findById($this->category);
        }
        return null;
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $checkUri = (new AppSlides())->find("uri = :uri AND id != :id", "uri={$this->uri}&id={$this->id}");

        if ($checkUri->count()) {
            $this->uri = "{$this->uri}-{$this->lastId()}";
        }

        return parent::save();
    }
}