<?php


namespace Source\Models\Brief;


use Source\Core\Model;

/**
 * Class AppSlides
 * @package Source\Models\Slide
 */
class AppBrief extends Model
{

    /**
     * AppSlides constructor.
     */
    public function __construct()
    {
        parent::__construct("brief", ["id"], ["title", "content"]);
    }

}
