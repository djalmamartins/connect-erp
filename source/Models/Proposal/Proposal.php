<?php

namespace Source\Models\Proposal;

use Source\Core\Model;

final class Proposal extends Model
{
    public function __construct()
    {
        parent::__construct('proposals', ['id'], ['protocol', 'name', 'email', 'whatsapp', 'condominium', 'units', 'profile', 'status']);
    }
}
