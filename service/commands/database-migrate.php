<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'status';
if (!in_array($mode, ['status', 'apply', 'baseline', 'rollback'], true)) {
    fwrite(STDERR, "Uso: php service/commands/database-migrate.php [status|apply|baseline|rollback]\n");
    exit(2);
}

$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $key => $value) {
        if (getenv((string)$key) === false) putenv($key . '=' . (string)$value);
    }
}

$environment = getenv('MOVESOS_ENV') ?: getenv('APP_ENV') ?: 'production';
$host = getenv('MOVESOS_DB_HOST') ?: getenv('DB_HOST') ?: '';
$port = (int)(getenv('MOVESOS_DB_PORT') ?: getenv('DB_PORT') ?: 3306);
$database = getenv('MOVESOS_DB_NAME') ?: getenv('DB_NAME') ?: '';
$user = getenv('MOVESOS_DB_USER') ?: getenv('DB_USER') ?: '';
$password = getenv('MOVESOS_DB_PASS');
if ($password === false) $password = getenv('DB_PASS');

if ($host === '' || $database === '' || $user === '' || $password === false) {
    fwrite(STDERR, "Banco não configurado. Defina DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS.\n");
    exit(2);
}
if ($mode === 'rollback' && $environment === 'production' && !in_array('--confirm-production', $argv, true)) {
    fwrite(STDERR, "Rollback em produção exige --confirm-production e backup prévio.\n");
    exit(2);
}
if ($mode === 'baseline' && $environment === 'production' && !in_array('--confirm-production', $argv, true)) {
    fwrite(STDERR, "Baseline em produção exige --confirm-production. Use somente se o schema já foi aplicado anteriormente.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        (string)$password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS movesos_schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        batch INT UNSIGNED NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = [];
    foreach ($pdo->query('SELECT migration, checksum FROM movesos_schema_migrations') as $row) {
        $applied[$row['migration']] = $row['checksum'];
    }
    $directory = $root . '/database/migrations';
    $files = array_values(array_filter(glob($directory . '/*.sql') ?: [], static fn(string $file): bool => !str_ends_with($file, '.down.sql')));
    sort($files, SORT_STRING);

    if ($mode === 'rollback') {
        $last = (int)$pdo->query('SELECT COALESCE(MAX(batch), 0) FROM movesos_schema_migrations')->fetchColumn();
        if ($last === 0) {
            echo "Nenhuma migration para reverter.\n";
            exit(0);
        }
        $rows = $pdo->prepare('SELECT migration FROM movesos_schema_migrations WHERE batch = :batch ORDER BY migration DESC');
        $rows->execute(['batch' => $last]);
        $delete = $pdo->prepare('DELETE FROM movesos_schema_migrations WHERE migration = :migration');
        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $down = $directory . '/' . preg_replace('/\\.sql$/', '.down.sql', (string)$name);
            if (!is_file($down)) {
                throw new RuntimeException("Migration sem reversão: {$name}");
            }
            $sql = trim((string)file_get_contents($down));
            if ($sql !== '') $pdo->exec($sql);
            $delete->execute(['migration' => $name]);
            echo "[revertida] {$name}\n";
        }
        exit(0);
    }
    foreach ($files as $file) {
        $down = preg_replace('/\\.sql$/', '.down.sql', $file);
        if (!is_file((string)$down)) {
            throw new RuntimeException('Migration sem arquivo de reversão: ' . basename($file));
        }
    }
    $pending = [];
    foreach ($files as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        if (isset($applied[$name])) {
            if (!hash_equals($applied[$name], $checksum)) {
                throw new RuntimeException("Migration alterada depois de aplicada: {$name}");
            }
            echo "[aplicada] {$name}\n";
        } else {
            echo "[pendente] {$name}\n";
            $pending[] = [$file, $name, $checksum];
        }
    }

    if ($mode === 'status') {
        echo count($pending) . " migration(s) pendente(s).\n";
        exit($pending ? 1 : 0);
    }

    $batch = (int)$pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM movesos_schema_migrations')->fetchColumn();
    $record = $pdo->prepare('INSERT INTO movesos_schema_migrations (migration, checksum, batch) VALUES (:migration, :checksum, :batch)');
    foreach ($pending as [$file, $name, $checksum]) {
        if ($mode === 'apply') {
            $sql = trim((string)file_get_contents($file));
            if ($sql !== '') $pdo->exec($sql);
        }
        $record->execute(['migration' => $name, 'checksum' => $checksum, 'batch' => $batch]);
        echo '[' . ($mode === 'apply' ? 'executada' : 'registrada') . "] {$name}\n";
    }
    echo $pending ? "Banco atualizado com sucesso.\n" : "Banco já está atualizado.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Falha na migration: {$exception->getMessage()}\n");
    exit(1);
}
