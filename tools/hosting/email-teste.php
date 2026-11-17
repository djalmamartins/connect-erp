<?php

declare(strict_types=1);

/**
 * Teste do e-mail da produção (ADR 0381), depois de preencher o SMTP no .env:
 *
 *   php tools/hosting/email-teste.php --para=voce@connectcondominios.com.br [--dkim-seletor=SELETOR] [--so-dns]
 *
 * Confere SPF, DKIM e DMARC do domínio do remetente e manda um e-mail de teste direto pelo SMTP (sem a fila).
 * Saída: 0 tudo certo, 1 DNS com erro ou envio recusado, 2 uso errado.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['para:', 'dkim-seletor:', 'so-dns']);
$from = (string)CONF_MAIL_SENDER['address'];
$domain = (string)substr((string)strrchr($from, '@'), 1);
if ($domain === '') {
    fwrite(STDERR, "MOVESOS_SMTP_FROM_ADDRESS vazio ou inválido no .env.\n");
    exit(2);
}

$dns = \Source\Support\MailDns::check($domain, isset($options['dkim-seletor']) ? (string)$options['dkim-seletor'] : null,
    \Source\Support\MailDns::resolver());
echo "DNS de e-mail de {$domain}:\n";
if ((@dns_get_record($domain, DNS_NS) ?: []) === []) {
    echo "  ERRO  o DNS não respondeu para {$domain} (domínio ainda não aponta ou o servidor está sem DNS); os itens abaixo podem estar errados.\n";
    $dns['ok'] = false;
}
foreach ($dns['items'] as $item) {
    $mark = ['ok' => 'OK   ', 'warning' => 'AVISO', 'error' => 'ERRO '][$item['status']];
    echo "  {$mark} {$item['name']}: {$item['message']}\n";
}
$exit = $dns['ok'] ? 0 : 1;

if (!isset($options['so-dns'])) {
    $to = (string)($options['para'] ?? '');
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "Informe --para=e-mail válido (ou --so-dns).\n");
        exit(2);
    }
    $mail = new \Source\Support\Email();
    $sent = $mail->bootstrap('Teste de e-mail do Connect ERP',
        '<p>Este é um e-mail de teste do servidor do Connect ERP (' . htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES) . ').</p>'
        . '<p>Se chegou na caixa de entrada (e não no spam), o envio está pronto.</p>', $to, 'Teste')->send();
    echo $sent ? "Envio: OK, aceito pelo SMTP ({$to}). Confira se chegou fora do spam.\n"
        : "Envio: ERRO, " . strip_tags((string)$mail->message()->getText()) . "\n";
    $exit = $sent ? $exit : 1;
}
exit($exit);
