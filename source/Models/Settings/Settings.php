<?php

namespace Source\Models\Settings;

use Source\Core\Model;

/**
 * ERP | Class Settings
 *
 * @author Djalma Martins
 * @package Source\Models\Settings
 */
class Settings extends Model
{
    /**
     *  Settings constructor.
     */
    public function __construct()
    {
        parent::__construct("settings", ["id"], ["mode"]);
    }

    /**
     * @return Settings|null
     */
    public static function dados(): ?Settings
    {
        return (new Settings())->find()->fetch();
    }

}