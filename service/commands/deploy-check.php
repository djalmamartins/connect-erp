<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];
$warnings = [];
$ok = static function (string $message): void { echo "[ok] {$message}\n"; };
$fail = static function (string $message) use (&$errors): void { $errors[] = $message; echo "[erro] {$message}\n"; };
$warn = static function (string $message) use (&$warnings): void { $warnings[] = $message; echo "[aviso] {$message}\n"; };

version_compare(PHP_VERSION, '8.2.0', '>=') ? $ok('PHP ' . PHP_VERSION) : $fail('PHP 8.2 ou superior é obrigatório.');
// Mesma lista do require ext-* do composer.json (ADR 0368; DeployExtensionsTest confere).
foreach (['pdo', 'pdo_mysql', 'intl', 'mbstring', 'json', 'openssl', 'fileinfo', 'curl', 'dom', 'simplexml', 'gd', 'zip'] as $extension) {
    extension_loaded($extension) ? $ok("Extensão {$extension}") : $fail("Extensão PHP ausente: {$extension}");
}
foreach (['vendor/autoload.php', 'container/cms/studio/v1/assets/studio.min.css', 'container/cms/studio/v1/assets/studio.min.js'] as $file) {
    is_file($root . '/' . $file) ? $ok($file) : $fail("Arquivo obrigatório ausente: {$file}");
}
foreach (['storage/logs', 'storage/cache', 'storage/sessions', 'storage/temp', 'storage/uploads', 'storage/images', 'storage/files', 'storage/output'] as $directory) {
    $absolute = $root . '/' . $directory;
    is_dir($absolute) && is_writable($absolute) ? $ok("{$directory} gravável") : $fail("Diretório ausente ou sem escrita: {$directory}");
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    $fail('.env não encontrado.');
} else {
    $env = parse_ini_string((string)preg_replace('/^\s*#.*$/m', '', (string)file_get_contents($envFile)), false, INI_SCANNER_RAW) ?: [];
    foreach (['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        array_key_exists($key, $env) ? $ok("{$key} configurado") : $fail("Variável ausente no .env: {$key}");
    }
    if (($env['APP_ENV'] ?? '') === 'production') {
        filter_var($env['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL) ? $fail('APP_DEBUG deve ser false em produção.') : $ok('Debug desativado');
        str_starts_with((string)($env['APP_URL'] ?? ''), 'https://') ? $ok('APP_URL usa HTTPS') : $warn('Use HTTPS na APP_URL de produção.');
        // Conferência de produção (ADR 0354): .env e, se o banco responder, contas de teste, 2FA da equipe e bancos.
        if (is_file($root . '/vendor/autoload.php')) {
            require_once $root . '/vendor/autoload.php';
            $ready = \Source\Support\DeployReadiness::env(array_map('strval', $env), time());
            // Limites do PHP (ADR 0362): lidos aqui no CLI; o site usa a mesma configuração do hPanel.
            $limits = [];
            foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'file_uploads'] as $directive) {
                $limits[$directive] = ini_get($directive);
            }
            $ready['warnings'] = array_merge($ready['warnings'], \Source\Support\DeployReadiness::php($limits));
            try {
                $pdo = \Source\Core\Connect::getInstance();
                if ($pdo instanceof \PDO) {
                    $db = \Source\Support\DeployReadiness::database($pdo);
                    $ready = ['errors' => array_merge($ready['errors'], $db['errors']), 'warnings' => array_merge($ready['warnings'], $db['warnings'])];
                }
            } catch (\Throwable $error) {
                $warn('Banco não respondeu: contas de teste e 2FA da equipe não foram conferidos.');
            }
            array_map($fail, $ready['errors']);
            array_map($warn, $ready['warnings']);
            $ready['errors'] === [] && $ready['warnings'] === [] && $ok('Conferência de produção sem pendências');
        }
    } else {
        $warn('APP_ENV ainda não está definido como production.');
    }
}

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/service/commands/database-migrate.php') . ' status 2>&1', $migrationOutput, $migrationCode);
foreach ($migrationOutput as $line) echo "[banco] {$line}\n";
if ($migrationCode === 1) $warn('Existem migrations pendentes; execute composer db:migrate durante a publicação.');
elseif ($migrationCode !== 0) $fail('Não foi possível validar as migrations do banco.');
else $ok('Schema do banco atualizado');

echo "\nResultado: " . count($errors) . " erro(s), " . count($warnings) . " aviso(s).\n";
exit($errors ? 1 : 0);
