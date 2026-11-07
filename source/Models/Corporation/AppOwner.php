<?php

namespace Source\Models\Corporation;

use Source\Core\Model;
use Source\Models\User;

/**
 * ERP | Class AppOwner
 *
 * @author Djalma Martins
 * @package Source\Models\Corporation
 */
class AppOwner extends Model
{
    /**
     * AppOwner constructor.
     */
    public function __construct()
    {
        parent::__construct("app_owner", ["id"], ["sub_of", "users_id"]);
    }


    /**
     * @return User
     */
    public function user(?string $id): ?User
    {

        return  (new User())->find("id = :id", "id='1'")->fetch();





    }

}