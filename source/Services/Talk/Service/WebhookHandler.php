<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\StatusUpdate;
use Source\Services\Talk\Gateway\GatewayFactory;

/**
 * Entrada dos webhooks dos dois tipos de canal.
 * Rota sugerida no ERP: POST /talk/webhook/{channelId}
 */
final class WebhookHandler
{
    public function __construct(private GatewayFactory $gateways, private ConversationService $conversations)
    {
    }

    /** @return array{status:int, processed:int} */
    public function handle(int $channelId, string $rawBody, array $headers): array
    {
        $gw = $this->gateways->forChannel($channelId);
        if (!$gw->verifyWebhook($rawBody, $headers)) {
            return ['status' => 401, 'processed' => 0];
        }
        $ch = $this->gateways->channelStatus($channelId);
        // Evolution avisa quando o celular conecta/desconecta: atualiza a tela Conexão WhatsApp
        $data = json_decode($rawBody, true);
        if (is_array($data) && strtolower(str_replace('_', '.', (string) ($data['event'] ?? ''))) === 'connection.update') {
            $s = strtolower((string) ($data['data']['state'] ?? ''));
            $this->gateways->markState($channelId, match ($s) { 'open' => 'connected', 'connecting' => 'connecting', default => 'disconnected' });
            return ['status' => 200, 'processed' => 1];
        }
        if ($ch === 'disabled') {
            return ['status' => 200, 'processed' => 0]; // conexão desativada: ignora (200 para o provedor não reenviar)
        }
        $n = 0;
        foreach ($gw->parseWebhook($rawBody) as $item) {
            if ($item instanceof InboundMessage) {
                $this->conversations->handleInbound($channelId, $item);
                $n++;
            } elseif ($item instanceof StatusUpdate) {
                $this->conversations->handleStatus($item);
                $n++;
            }
        }
        return ['status' => 200, 'processed' => $n];
    }
}
