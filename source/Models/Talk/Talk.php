<?php

namespace Source\Models\Talk;

use Source\Core\Model;

/**
 * ERP | Class Talk
 *
 * @author Djalma Martins
 * @package Source\Models\Talk
 */
class Talk extends Model
{
    /**
     * Talk constructor.
     */
    public function __construct()
    {
        parent::__construct("talk", ["id"], ["title", "cover", "content", "place"]);
    }
}