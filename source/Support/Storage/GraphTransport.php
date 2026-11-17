<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/** Pedido HTTP cru para o Microsoft Graph (ADR 0373). Não segue redirecionamento: quem chama decide. */
interface GraphTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location: string}
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): array;
}
