<?php

declare(strict_types=1);

/**
 * Diagnóstico do envio de e-mail (ADR 0655). Só lê, nunca mostra senha:
 *
 *   docker compose exec -T app php tools/email-diagnostico.php [--enviar]
 *   docker compose exec -T scheduler php tools/email-diagnostico.php [--enviar]
 *
 * Mostra o modo (log, sandbox, production), a caixa de teste, chaves do .env com nome errado, o SMTP em uso
 * (servidor, porta, segurança, de onde vem cada campo) e os últimos erros da mail_queue. Com --enviar, manda um
 * e-mail de teste direto pelo SMTP (sem a fila) para a caixa de teste e mostra o erro exato do servidor.
 * Saída: 0 sem problema encontrado, 1 com problema.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Services\Erp\NotificationDispatcher;
use Source\Support\MailConfig;
use Source\Support\MailSandbox;

$problems = 0;
$say = static function (string $mark, string $text) use (&$problems): void {
    if ($mark === 'ERRO ') {
        $problems++;
    }
    echo "  {$mark} {$text}\n";
};

$appEnv = defined('CONF_APP_ENV') ? (string)CONF_APP_ENV : 'production';
$delivery = (string)(getenv('ERP_MAIL_DELIVERY') ?: '');
$sandboxTo = getenv('ERP_MAIL_SANDBOX_TO') ?: null;
$queueSend = (string)(getenv('LAB_MAIL_QUEUE_SEND') ?: '');

echo "Ambiente e modo:\n";
$say('OK   ', "APP_ENV = {$appEnv}");
$say($delivery === '' ? 'ERRO ' : 'OK   ', 'ERP_MAIL_DELIVERY = ' . ($delivery === '' ? '(vazio: fica em "log", nada sai)' : $delivery));
$say($sandboxTo && filter_var($sandboxTo, FILTER_VALIDATE_EMAIL) ? 'OK   ' : 'AVISO', 'ERP_MAIL_SANDBOX_TO = ' . ($sandboxTo ? MailSandbox::mask($sandboxTo) : '(vazio)'));
$mode = NotificationDispatcher::deliveryMode($appEnv, $delivery ?: 'log', $sandboxTo);
$say('OK   ', "modo dos avisos do ERP = {$mode}");
$target = MailSandbox::current();
$say('OK   ', 'fila de e-mail vai para ' . ($target ? 'a caixa de teste ' . MailSandbox::mask($target) . ' (com [TESTE])' : 'o destinatário gravado em cada e-mail'));
// O compose só passa LAB_MAIL_QUEUE_SEND ao scheduler; no container app ela pode aparecer vazia.
$say($queueSend === '1' ? 'OK   ' : 'AVISO', 'LAB_MAIL_QUEUE_SEND = ' . ($queueSend === '' ? '(vazio)' : $queueSend)
    . ($queueSend === '1' ? '' : ' (no scheduler precisa ser 1; depois de mudar o .env, recrie o container)'));

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/m', (string)file_get_contents($envFile), $m);
    foreach (MailSandbox::typos($m[1]) as $wrong => $right) {
        $say('ERRO ', ".env tem {$wrong}: o nome certo é {$right} (a linha errada é ignorada)");
    }
}

echo "\nSMTP em uso (senha nunca aparece):\n";
$settings = Connect::getInstance()->query('SELECT * FROM settings LIMIT 1')->fetch() ?: [];
$smtp = MailConfig::current($settings);
foreach (['host' => 'servidor', 'port' => 'porta', 'secure' => 'segurança', 'from_address' => 'remetente'] as $key => $label) {
    $value = (string)$smtp[$key];
    $say($value === '' ? 'ERRO ' : 'OK   ', "{$label} = " . ($value === '' ? '(vazio)' : $value) . " (de {$smtp['source'][$key]})");
}
$say('OK   ', 'autenticação = ' . (filter_var($smtp['auth'], FILTER_VALIDATE_BOOLEAN) ? 'sim' : 'não') . " (de {$smtp['source']['auth']})");
foreach (['user' => 'usuário', 'pass' => 'senha'] as $key => $label) {
    $state = $smtp['source'][$key] === 'unreadable' ? 'não abre (gravada com outra CONNECT_2FA_KEY; grave de novo no Studio)'
        : ((string)$smtp[$key] === '' ? 'vazio' : 'preenchido');
    $say($state === 'preenchido' ? 'OK   ' : 'ERRO ', "{$label}: {$state} (de {$smtp['source'][$key]})");
}
$port = (string)$smtp['port'];
$secure = strtolower((string)$smtp['secure']);
if (($port === '465' && $secure !== 'ssl') || ($port === '587' && $secure !== 'tls')) {
    $say('ERRO ', "porta {$port} com segurança \"{$secure}\": use 465 com ssl ou 587 com tls");
}

echo "\nFila de e-mail (mail_queue):\n";
$pdo = Connect::getInstance();
foreach ($pdo->query('SELECT status, COUNT(*) n FROM mail_queue GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $say('OK   ', "{$row['status']}: {$row['n']}");
}
$rows = $pdo->query("SELECT id, status, attempts, recipient_email, error_message, last_attempt_at FROM mail_queue
    WHERE error_message IS NOT NULL AND error_message <> '' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $say('ERRO ', "#{$row['id']} {$row['status']} ({$row['attempts']}x, {$row['last_attempt_at']}) para "
        . MailSandbox::mask((string)$row['recipient_email']) . ': ' . trim(html_entity_decode((string)$row['error_message'], ENT_QUOTES, 'UTF-8')));
}
if ($rows === []) {
    $say('OK   ', 'nenhum erro gravado na fila');
}

if (in_array('--enviar', $argv, true)) {
    echo "\nEnvio direto de teste:\n";
    $to = $target ?? ($sandboxTo ?: '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $say('ERRO ', 'sem caixa de teste: preencha ERP_MAIL_SANDBOX_TO no .env');
    } else {
        $mail = (new \Source\Support\Email())->timeout(20);
        $sent = $mail->bootstrap(MailSandbox::subject('Diagnóstico de e-mail do ERP Connect Condomínios'),
            '<p>E-mail de teste do diagnóstico (' . date('d/m/Y H:i') . '). Se chegou, o SMTP está certo.</p>', $to, 'Teste')->send();
        $say($sent ? 'OK   ' : 'ERRO ', $sent ? 'aceito pelo SMTP para ' . MailSandbox::mask($to) . '; confira a caixa e o spam'
            : 'o SMTP recusou: ' . trim(html_entity_decode(strip_tags((string)$mail->message()->getText()), ENT_QUOTES, 'UTF-8')));
    }
}

echo $problems === 0 ? "\nNenhum problema encontrado.\n" : "\n{$problems} problema(s) encontrado(s).\n";
exit($problems === 0 ? 0 : 1);
