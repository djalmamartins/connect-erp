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
            if (self::databaseDisabled()) {
                throw new \RuntimeException('Banco de dados desativado para esta execução (MOVESOS_TEST_DB_DISABLED=1).');
            }
            try {
                self::$instance = new PDO(
                    "mysql:host=" . CONF_DB_HOST . ";port=" . CONF_DB_PORT . ";dbname=" . CONF_DB_NAME . ";charset=utf8mb4",
                    CONF_DB_USER,
                    CONF_DB_PASS,
                    self::OPTIONS
                );
            } catch (PDOException $exception) {
                AppLogger::exception($exception, 'database', ['event_type' => 'database_connection_failed']);
                // CLI, testes e boot anterior às constantes de URL não podem redirecionar (A6, C8):
                // falham com exceção clara em vez de erro fatal dentro de url().
                if (PHP_SAPI === 'cli' || (defined('CONF_APP_ENV') && CONF_APP_ENV === 'testing') || !defined('CONF_URL_SSL')) {
                    throw new \RuntimeException('Banco de dados indisponível: ' . $exception->getMessage(), 0, $exception);
                }
                redirect("/ops/connect");
            }
        }

        return self::$instance;
    }

    /**
     * Modo sem banco, aceito somente com MOVESOS_ENV=testing: permite rodar a suíte
     * unit fora do container. Sem efeito em produção ou local.
     */
    public static function databaseDisabled(): bool
    {
        return defined('CONF_APP_ENV') && CONF_APP_ENV === 'testing'
            && filter_var(getenv('MOVESOS_TEST_DB_DISABLED') ?: false, FILTER_VALIDATE_BOOL);
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
