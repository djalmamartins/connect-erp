<?php

namespace Source\Core;

use Source\Support\Message;
use Source\Support\Seo;

/**
 * ERP by MOVES | Class Controller
 *
 * @author Djalma Martins
 * @package Source\Core
 */
class Controller
{
    /** @var View */
    protected $view;

    /** @var Seo */
    protected $seo;

    /** @var Message */
    protected $message;

    /**
     * Controller constructor.
     * @param string|null $pathToViews
     */
    public function __construct(?string $pathToViews = null)
    {
        $this->view = $pathToViews ? new View($pathToViews) : null;
        $this->seo = new Seo();
        $this->message = new Message();
    }
}