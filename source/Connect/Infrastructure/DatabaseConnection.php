<?php
declare(strict_types=1);
namespace Connect\Infrastructure;
use PDO;
final class DatabaseConnection
{
    public static function create(): PDO
    {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'connect_erp';
        $username = getenv('DB_USERNAME') ?: 'connect_erp';
        $password = getenv('DB_PASSWORD') ?: '';
        return new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
