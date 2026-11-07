<?php

/**
 * Ponto de compatibilidade para inclusões legadas.
 * A configuração de ambiente pertence a Config.php.
 */
if (!defined("CONF_DB_HOST")) {
    require __DIR__ . "/Config.php";
}
