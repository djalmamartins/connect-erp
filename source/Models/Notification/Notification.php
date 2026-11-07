<?php

namespace Source\Models\Notification;

use Source\Core\Model;

/**
 * ERP | Class Notification
 *
 * @author Djalma Martins
 * @package Source\Models
 */
class Notification extends Model
{
    /**
     * Notification constructor.
     */
    public function __construct()
    {
        parent::__construct("notifications", ["id"], ["users_id", "image", "title", "link"]);
    }
}