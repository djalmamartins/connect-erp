<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\Inter;

use RuntimeException;

/**
 * mTLS com o Inter (ADR 0156). Certificado e chave vão para o cURL em memória (CURLOPT_SSLCERT_BLOB
 * e CURLOPT_SSLKEY_BLOB): nada é gravado em disco. Verificação do servidor sempre ligada.
 */
final class CurlInterHttp implements InterHttp
{
    public function __construct(private int $timeoutSeconds = 20)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        if (!defined('CURLOPT_SSLCERT_BLOB') || !defined('CURLOPT_SSLKEY_BLOB')) {
            throw new RuntimeException('Este PHP/cURL não aceita certificado em memória (precisa de cURL 7.71 ou mais novo).');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Falha ao preparar a conexão com o banco.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_SSLCERT_BLOB => $certificatePem,
            CURLOPT_SSLKEY_BLOB => $privateKeyPem,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false || $status === 0) {
            // A mensagem do cURL não leva credencial; vai para o log, não para a tela.
            throw new RuntimeException('Sem resposta do Inter: ' . mb_substr($error, 0, 160));
        }
        return ['status' => $status, 'body' => (string)$response];
    }
}
