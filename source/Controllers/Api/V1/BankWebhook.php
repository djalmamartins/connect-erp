<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankWebhooks;
use Source\Support\AppLogger;
use Source\Support\ClientIp;

/**
 * Webhook de cobrança do banco (ADR 0156): POST /api/v1/webhooks/bank/{adapter}/{secret}.
 * Rota pública por natureza (quem chama é o banco, sem sessão nem CSRF). A proteção é o segredo
 * do endereço (192 bits, só o sha256 no banco), a consulta de cada título no banco antes de mudar
 * estado (BankWebhooks) e, no servidor, mTLS com o certificado do banco quando há proxy próprio.
 * Na hospedagem sem proxy (ADR 0360), BANK_WEBHOOK_ALLOWED_IPS limita a origem aos IPs/CIDR do banco.
 * Resposta sem detalhes: 404 para segredo errado, 503 para o banco reenviar.
 */
final class BankWebhook
{
    public function receive(array $data): void
    {
        $adapter = (string)($data['adapter'] ?? '');
        $secret = (string)($data['secret'] ?? '');
        header('Cache-Control: no-store');
        if (!in_array($adapter, BankAdapters::NAMES, true) || $adapter === BankAdapters::DEFAULT || ($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado.']]);
            return;
        }
        if (!ClientIp::allowed(ClientIp::resolve(), (string)(getenv('BANK_WEBHOOK_ALLOWED_IPS') ?: ''))) {
            AppLogger::log('warning', 'Webhook do banco recusado: IP fora da lista.', ['event_type' => 'bank_webhook_ip_blocked', 'adapter' => $adapter], 'erp');
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado.']]);
            return;
        }
        $body = (string)file_get_contents('php://input', false, null, 0, BankWebhooks::MAX_BYTES + 1);
        try {
            $result = (new BankWebhooks())->receive($adapter, $secret, $body);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_webhook_failed', 'adapter' => $adapter]);
            JsonResponse::send(503, ['error' => ['code' => 'retry', 'message' => 'Tente de novo mais tarde.']]);
            return;
        }
        if ($result['http'] >= 400) {
            JsonResponse::send($result['http'], ['error' => ['code' => $result['status'], 'message' => 'Notificação não aceita.']]);
            return;
        }
        JsonResponse::send($result['http'], ['data' => ['status' => $result['status']]]);
    }
}
