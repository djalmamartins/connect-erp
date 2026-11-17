<?php

declare(strict_types=1);

/**
 * Conferência por HTTP depois de publicar (ADR 0356), do Mac ou do próprio servidor:
 *   php tools/hosting/smoke-http.php --domain=connectcondominios.com.br
 *
 * Só GET, sem login e sem gravar nada: páginas públicas e telas que mandam para o login nos 4 endereços, /api/v1/health
 * com "ready", HTTP indo para HTTPS, pastas internas barradas e cabeçalhos de segurança. Sai com código 1 se algo falhar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Sem o autoload: roda de qualquer máquina, sem .env nem banco.
require dirname(__DIR__, 2) . '/source/Support/SmokeHttp.php';

use Source\Support\SmokeHttp;

$options = getopt('', ['domain:']);
$domain = (string)($options['domain'] ?? '');
if (!preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $domain)) {
    fwrite(STDERR, "Informe --domain=connectcondominios.com.br\n");
    exit(2);
}

$failed = 0;
$checks = SmokeHttp::checks($domain);
foreach ($checks as $check) {
    $curl = curl_init($check['url']);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'connect-smoke-http']);
    $response = curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($response)) {
        $problem = 'sem resposta (' . $error . ')';
    } else {
        $problem = SmokeHttp::evaluate($check, $code, SmokeHttp::parseHeaders(substr($response, 0, $size)), (string)substr($response, $size));
    }
    $failed += $problem !== null ? 1 : 0;
    printf("%-7s %3d  %s%s\n", $problem === null ? 'OK' : 'FALHOU', $code, $check['name'], $problem !== null ? ' — ' . $problem : '');
}
printf("\n%d de %d conferências certas.\n", count($checks) - $failed, count($checks));
exit($failed > 0 ? 1 : 0);
