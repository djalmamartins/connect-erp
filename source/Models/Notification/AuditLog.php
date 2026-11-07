<?php

namespace Source\Models\Notification;

use Source\Core\Model;

class AuditLog extends Model
{
    public function __construct()
    {
        parent::__construct("system_audit_logs", ["id"], ["action", "entity", "description", "severity"]);
    }
}
