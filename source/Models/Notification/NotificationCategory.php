<?php

namespace Source\Models\Notification;

use Source\Core\Model;

class NotificationCategory extends Model
{
    public function __construct()
    {
        parent::__construct("notifications_categories", ["id"], ["title", "uri", "description", "type"]);
    }

    public function findByUri(string $uri): ?NotificationCategory
    {
        return $this->find("uri = :uri", "uri={$uri}")->fetch();
    }
}
