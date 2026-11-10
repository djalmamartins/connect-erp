<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\Inter;

/**
 * Transporte HTTP do Inter (ADR 0156). Separado do adaptador para os testes usarem respostas
 * sintéticas sem rede. A implementação real (CurlInterHttp) usa mTLS com certificado e chave
 * em memória.
 */
interface InterHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     * @throws \RuntimeException sem resposta (timeout, TLS, DNS)
     */
    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array;
}
