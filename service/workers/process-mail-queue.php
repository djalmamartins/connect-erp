#!/usr/bin/env php
<?php

declare(strict_types=1);

// O worker é executado pelo CLI, onde HTTP_HOST não existe. No ambiente local,
// reutiliza APP_ENV do arquivo .env para que a conexão não seja interpretada
// como produção sem credenciais.
if (!getenv("MOVESOS_ENV")) {
    $environmentFile = dirname(__DIR__, 2) . "/.env";
    if (is_file($environmentFile)) {
        $environment = parse_ini_file($environmentFile, false, INI_SCANNER_RAW) ?: [];
        if (!empty($environment["APP_ENV"])) putenv("MOVESOS_ENV=" . $environment["APP_ENV"]);
        foreach (["HOST", "USER", "PASS", "NAME"] as $key) {
            if (!empty($environment["DB_{$key}"])) putenv("MOVESOS_DB_{$key}=" . $environment["DB_{$key}"]);
        }
    }
}

$_SERVER["REQUEST_URI"] = $_SERVER["REQUEST_URI"] ?? "/cli/mail-queue";

$root = dirname(__DIR__, 2);
require $root . "/vendor/autoload.php";

$lock = fopen(sys_get_temp_dir() . "/movesos-mail-queue.lock", "c");
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "A fila de e-mails já está sendo processada.\n");
    exit(0);
}

try {
    $scheduled = (new \Source\Support\Communication())->dispatchScheduled(100);
    $result = (new \Source\Support\Email())->sendQueue(5, 50);
    fwrite(STDOUT, sprintf(
        "Agendamentos liberados: %d | processados: %d | enviados: %d | novas tentativas: %d | falharam: %d\n",
        $scheduled, $result["processed"], $result["sent"], $result["retry"], $result["failed"]
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, "Erro ao processar a fila: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
