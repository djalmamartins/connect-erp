<?php

declare(strict_types=1);

/**
 * Teste de carga dos limites de tentativas (ADR 0348): simula quem tenta senhas trocando de sessão a cada tentativa
 * (abre a tela de login, pega o cookie e o token, manda uma senha errada) com vários pedidos ao mesmo tempo, e conta
 * quantos passaram antes do bloqueio. Com o limite por IP (ADR 0326), o esperado é parar em ipLimitFor(8) = 40.
 *
 * Uso (só contra o lab ou um servidor de teste; nunca contra produção sem combinar):
 *   php tools/hosting/load-test-limits.php --base=http://127.0.0.1:8099 --host=erp.connect.lab --path=/login \
 *       --total=200 --parallel=20 [--email=ninguem@connect.lab] [--vary-email]
 *
 * --vary-email troca o e-mail a cada tentativa, para medir só o limite por IP (sem o limite por conta).
 *
 * Imprime JSON com: total, aceitos (respostas que chegaram a conferir a senha), bloqueados ("Muitas tentativas"),
 * outros, tempo total e tempo por pedido (mediana e p95).
 */

$options = getopt('', ['base:', 'host::', 'path::', 'total::', 'parallel::', 'email::', 'blocked-text::', 'vary-email']);
$base = rtrim((string)($options['base'] ?? ''), '/');
if ($base === '' || !preg_match('~^https?://~', $base)) {
    fwrite(STDERR, "Informe --base=http://servidor\n");
    exit(2);
}
$host = (string)($options['host'] ?? '');
$path = (string)($options['path'] ?? '/login');
$total = max(1, (int)($options['total'] ?? 100));
$parallel = max(1, min(100, (int)($options['parallel'] ?? 10)));
$email = (string)($options['email'] ?? 'ninguem@connect.lab');
$blockedText = (string)($options['blocked-text'] ?? 'Muitas tentativas');

$varyEmail = isset($options['vary-email']);
$headers = $host !== '' ? ['Host: ' . $host] : [];

/** Uma tentativa: GET da tela (sessão nova) e POST com senha errada. */
$attempt = static function () use ($base, $path, $headers, $email, $varyEmail): array {
    $jar = tempnam(sys_get_temp_dir(), 'lt');
    $get = curl_init($base . $path);
    curl_setopt_array($get, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    $page = (string)curl_exec($get);
    curl_close($get);
    $csrf = preg_match("~name=['\"]csrf['\"] value=['\"]([a-f0-9]+)~", $page, $m) ? $m[1] : '';
    $post = curl_init($base . $path);
    curl_setopt_array($post, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query(['csrf' => $csrf, 'email' => $varyEmail ? str_replace('@', '+' . bin2hex(random_bytes(4)) . '@', $email) : $email, 'password' => 'senha-errada-' . bin2hex(random_bytes(3))])]);
    return [$post, $jar];
};

$started = microtime(true);
$results = ['total' => $total, 'aceitos' => 0, 'bloqueados' => 0, 'outros' => 0, 'primeiro_bloqueio' => null];
$times = [];
$sent = 0;
while ($sent < $total) {
    $batch = min($parallel, $total - $sent);
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $batch; $i++) {
        [$handle, $jar] = $attempt();
        curl_multi_add_handle($multi, $handle);
        $handles[] = [$handle, $jar];
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    foreach ($handles as $n => [$handle, $jar]) {
        $body = (string)curl_multi_getcontent($handle);
        $times[] = (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME);
        $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (str_contains($body, $blockedText) || $code === 429) {
            $results['bloqueados']++;
            $results['primeiro_bloqueio'] ??= $sent + $n + 1;
        } elseif ($code === 200 && !str_contains($body, 'Sessão expirada')) {
            $results['aceitos']++;
        } else {
            $results['outros']++;
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        @unlink($jar);
    }
    curl_multi_close($multi);
    $sent += $batch;
}
sort($times);
$results['segundos'] = round(microtime(true) - $started, 2);
$results['mediana_s'] = round($times[(int)floor((count($times) - 1) / 2)], 3);
$results['p95_s'] = round($times[(int)floor((count($times) - 1) * 0.95)], 3);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
