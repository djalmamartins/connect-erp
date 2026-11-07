<?php


namespace Source\Models\Slide;


use Source\Core\Model;
use Source\Models\Slide\SlideCategory;
use Source\Models\User;


/**
 * Class AppSlide
 * @package Source\Models\Slide
 */
class AppSlide extends Model
{

    /**
     * AppSlide constructor.
     */
    public function __construct()
    {
        parent::__construct("slide", ["id"], ["title", "cover", "content"]);
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
     * @return AppSlide|null
     */
    public function findByUri(string $uri, string $columns = "*"): ?AppSlide
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
     * @return SlideCategory|null
     */
    public function category(): ?SlideCategory
    {
        if ($this->category) {
            return (new SlideCategory())->findById($this->category);
        }
        return null;
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $checkUri = (new AppSlide())->find("uri = :uri AND id != :id", "uri={$this->uri}&id={$this->id}");

        if ($checkUri->count()) {
            $this->uri = "{$this->uri}-{$this->lastId()}";
        }

        return parent::save();
    }
}