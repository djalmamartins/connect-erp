<?php

declare(strict_types=1);

/**
 * Worker do Connect Talk dentro do ERP (ADR 0349): envia a fila de mensagens, baixa a mídia recebida, faz a triagem
 * da Jack, os agendamentos e o envio em massa. É o bin/talk-worker do Talk separado, só que com prazo: na hospedagem
 * sem processo contínuo (Cloud Startup) ele roda pelo cron a cada minuto e sai antes do próximo.
 *
 *   php tools/talk-worker.php                 roda por 55 s (cron do hPanel: * * * * *)
 *   php tools/talk-worker.php --segundos=N    roda por N s (1 a 3600)
 *   php tools/talk-worker.php --check         sai com 0 se o worker deu sinal de vida no último minuto e meio
 *
 * Duas chamadas ao mesmo tempo não brigam: a segunda sai na hora (trava em storage/talk/worker.lock), e a fila usa
 * SKIP LOCKED de qualquer forma. No laboratório (TALK_ENV=lab) não precisa: a fila anda ao fim de cada requisição.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Talk\TalkApp;

/** @param list<string> $argv */
function talk_worker_seconds(array $argv): int
{
    foreach ($argv as $arg) {
        if (preg_match('/^--segundos=(\d+)$/', $arg, $m)) {
            return max(1, min(3600, (int) $m[1]));
        }
    }
    return 55;
}

$app = TalkApp::build();
$db = $app['db'];

if (in_array('--check', $argv, true)) {
    $hb = $db->one("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM talk_settings WHERE name = 'worker_heartbeat'");
    $age = $hb ? (int) $hb['age'] : null;
    fwrite(STDOUT, $age === null ? "worker nunca rodou\n" : "último sinal há {$age}s\n");
    exit($age !== null && $age <= 90 ? 0 : 1);
}

$lockDir = dirname(__DIR__) . '/storage/talk';
is_dir($lockDir) || @mkdir($lockDir, 0770, true);
$lockFile = $lockDir . '/worker.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[talk-worker] outro worker já está rodando; saindo\n");
    exit(0);
}

$beat = static function () use ($db): void {
    $db->run(
        "INSERT INTO talk_settings (name, value) VALUES ('worker_heartbeat', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [json_encode(['at' => date('c'), 'pid' => getmypid(), 'host' => gethostname(), 'mode' => 'cron'])]
    );
};

$worker = $app['worker'];
$deadline = microtime(true) + talk_worker_seconds($argv);
$lastTriage = 0.0;
$lastBeat = 0.0;
$errors = 0;
$total = 0;
while (microtime(true) < $deadline) {
    try {
        if (microtime(true) - $lastBeat >= 15) {
            $beat();
            $lastBeat = microtime(true);
        }
        if (microtime(true) - $lastTriage >= 5) {   // triagem da Jack e agendamentos a cada 5 s
            $app['conversations']->triage();
            $app['schedules']->runDue();
            $lastTriage = microtime(true);
        }
        $done = $worker->runOnce() + $worker->fetchMedia() + $app['campaigns']->runDue();
        $total += $done;
        $errors = 0;
        if ($done === 0) {
            usleep(500_000);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '[talk-worker] erro: ' . $e->getMessage() . "\n");
        if (++$errors >= 20) {
            fwrite(STDERR, "[talk-worker] 20 erros seguidos; saindo (o cron chama de novo no próximo minuto)\n");
            exit(1);
        }
        sleep(2);
    }
}
flock($lock, LOCK_UN);
fwrite(STDOUT, "[talk-worker] fim do turno: {$total} tarefas\n");
