<?php

declare(strict_types=1);

/**
 * Instalação limpa do banco (ADR 0396): só num banco vazio. Estrutura base + catálogos + configuração, depois as
 * migrations atuais e o lembrete dos próximos passos.
 *
 *   composer db:install
 */

$root = dirname(__DIR__, 2);
// Sem vendor/autoload.php: o autoload liga o sistema, que lê a tabela settings, e o banco ainda está vazio.
require $root . '/source/Support/CleanInstall.php';

$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (parse_ini_string((string)preg_replace('/^\s*#.*$/m', '', (string)file_get_contents($envFile)), false, INI_SCANNER_RAW) ?: [] as $key => $value) {
        if (getenv((string)$key) === false) putenv($key . '=' . (string)$value);
    }
}
$host = getenv('MOVESOS_DB_HOST') ?: getenv('DB_HOST') ?: '';
$port = (int)(getenv('MOVESOS_DB_PORT') ?: getenv('DB_PORT') ?: 3306);
$database = getenv('MOVESOS_DB_NAME') ?: getenv('DB_NAME') ?: '';
$user = getenv('MOVESOS_DB_USER') ?: getenv('DB_USER') ?: '';
$password = getenv('MOVESOS_DB_PASS');
if ($password === false) $password = getenv('DB_PASS');
if ($host === '' || $database === '' || $user === '') {
    fwrite(STDERR, "Banco não configurado. Defina DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS.\n");
    exit(2);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, (string)$password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $report = \Source\Support\CleanInstall::run($pdo, $root, (string)(getenv('APP_URL') ?: ''));
} catch (Throwable $error) {
    fwrite(STDERR, "Instalação parada: {$error->getMessage()}\n");
    exit(1);
}
echo "Estrutura base: {$report['tables']} tabelas.\n";
echo "Catálogos das migrations antigas: {$report['legacy_ok']} comandos; " . count($report['legacy_skipped']) . " não se aplicam à estrutura nova:\n";
foreach ($report['legacy_skipped'] as $line) {
    echo "  - {$line}\n";
}
echo $report['settings'] ? "Configuração inicial criada (ajuste em Studio > Configurações).\n" : "Configuração já existia.\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/service/commands/database-migrate.php') . ' apply', $code);
if ($code !== 0) {
    exit($code);
}
echo "\nPróximos passos: php tools/hosting/criar-admin.php --email=... --nome=... --sobrenome=... --cpf=...\n";
echo "e depois php service/commands/deploy-check.php.\n";
