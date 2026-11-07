<?php

namespace Source\Models\Banking;

use Source\Core\Model;

/**
 * ERP | Class Banking
 *
 * @author Djalma Martins
 * @package Source\Models\Banking
 */
class Banking extends Model
{
    /**
     * AppCondominium constructor. constructor.
     */
    public function __construct()
    {
        parent::__construct("app_banking", [id], ["", "", ""]);
    }
}