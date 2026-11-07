<?php

namespace Source\Models;

use Source\Core\Model;

/**
 * ERP | Address constructor.
 *
 * @author Djalma Martins
 * @package Source\Models\Address
 */
class Address extends Model
{
    /**
     * Address constructor.
     */
    public function __construct()
    {
        parent::__construct("app_address", ["id"], ["code", "city", "state", "district", "street", "number"]);
    }
}