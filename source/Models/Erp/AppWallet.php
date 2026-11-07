<?php

namespace Source\Models\Erp;

use Source\Core\Model;
use Source\Models\Corporation\AppCondominium;

class AppWallet extends Model
{

    public function __construct()
    {
        parent::__construct("app_wallets", ["id"], ["condominium_id", "wallet"]);
    }

    public function start(AppCondominium $condo): AppWallet
    {
        if(!$this->find("condominium_id = :condo", "condo={$condo->id}")->count()){
            $this->condominium_id = $condo->id;
            $this->wallet = "Fundo de Reserva";
            $this->free = true;
            $this->save();
        }
        return $this;
    }

}