<?php

namespace Source\Models\Notification;

use Source\Core\Model;

class NotificationMessage extends Model
{
    public function __construct()
    {
        parent::__construct("notification_messages", ["id"], ["title", "body", "audience", "severity", "status"]);
    }
}
