<?php

namespace Source\Models\Corporation;

use Source\Core\Model;
use Source\Models\User;

/**
 * ERP | Class AppUnits
 *
 * @author Djalma Martins
 * @package Source\Models\Corporation
 */
class AppUnits extends Model
{
    /**
     * AppUnits constructor.
     */
    public function __construct()
    {
        parent::__construct("app_units", ["id"], ["sub_of", "units"]);
    }

}