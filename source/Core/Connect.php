<?php

namespace Source\Core;

use PDO;
use PDOException;
use Source\Support\AppLogger;

/**
 * @package Source\Core
 */
class Connect
{
    /**
     * @const array
     */
    private const OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_CASE => PDO::CASE_NATURAL
    ];

    /**
     * @var  PDO
     */
    private static $instance;

    /**
     * @return PDO|null
     */
    public static function getInstance(): ?PDO
    {
        if (empty(self::$instance)) {
            try {
                self::$instance = new PDO(
                    "mysql:host=" . CONF_DB_HOST . ";port=" . CONF_DB_PORT . ";dbname=" . CONF_DB_NAME . ";charset=utf8mb4",
                    CONF_DB_USER,
                    CONF_DB_PASS,
                    self::OPTIONS
                );
            } catch (PDOException $exception) {
                AppLogger::exception($exception, 'database', ['event_type' => 'database_connection_failed']);
                redirect("/ops/connect");
            }
        }

        return self::$instance;
    }

    /**
     * Connect constructor.
     */
    private function __construct()
    {
    }

    /**
     * Connect clone.
     */
    private function __clone()
    {
    }

}
