<?php

namespace Source\Models\Slide;

use Source\Core\Model;


/**
 * Class CategorySlide
 * @package Source\Models
 */
class CategorySlide extends Model
{

    /**
     * CategorySlide constructor.
     */
    public function __construct()
    {
        parent::__construct("categories_slide", ["id"], ["title", "description"]);
    }


    /**
     * @param string $uri
     * @param string $columns
     * @return CategorySlide|null
     */
    public function findByUri(string $uri, string $columns = "*"): ?CategorySlide
    {
        $find = $this->find("uri = :uri", "uri={$uri}", $columns);
        return $find->fetch();
    }


    /**
     * @return AppSlides
     */
    public function posts(): AppSlides
    {
        return (new AppSlides())->find("category = :id", "id={$this->id}");
    }


    /**
     * @return bool
     */
    public function save(): bool
    {
        $checkUri = (new CategorySlide())->find("uri = :uri AND id != :id", "uri={$this->uri}&id={$this->id}");

        if ($checkUri->count()) {
            $this->uri = "{$this->uri}-{$this->lastId()}";
        }
        return parent::save();
    }
}