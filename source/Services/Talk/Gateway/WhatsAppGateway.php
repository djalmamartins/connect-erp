<?php
declare(strict_types=1);

namespace Source\Services\Talk\Gateway;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\SendResult;
use Source\Services\Talk\Domain\StatusUpdate;

/**
 * Um provedor de WhatsApp. O restante do sistema só conhece esta interface,
 * então dá para ter canais QR Code (Evolution) e oficiais (Meta) lado a lado.
 */
interface WhatsAppGateway
{
    public function sendText(string $toPhone, string $text): SendResult;

    /**
     * Envio por modelo aprovado (obrigatório na API oficial fora da janela de 24h,
     * ex.: boletos em massa). Canais QR Code enviam o texto renderizado.
     * @param list<string> $params
     */
    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult;

    /**
     * Mostra "digitando…" para o cliente por alguns segundos (sem enviar mensagem).
     * $replyToProviderId: última mensagem recebida (a API oficial exige para o indicador).
     */
    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void;

    /**
     * Envia um arquivo. $type: image | audio | video | document. Áudio não tem legenda.
     */
    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult;

    /**
     * Baixa a mídia de uma mensagem recebida ($ref = InboundMessage::mediaRef).
     * @return array{bytes:string, mime:?string}
     */
    public function downloadMedia(string $ref): array;

    /**
     * Conexão do número. state: connected | connecting | disconnected.
     * connect(): pede a conexão; no QR Code devolve a imagem (data URL) para escanear.
     * $webhookUrl/$secret: onde o provedor deve avisar das mensagens (QR Code configura sozinho).
     * @return array{state:string, qr?:?string, phone?:?string, detail?:?string}
     */
    public function connect(?string $webhookUrl = null, ?string $secret = null): array;

    /** @return array{state:string, phone?:?string, detail?:?string} */
    public function connectionState(): array;

    /** Desconecta o número (QR Code: sai do WhatsApp Web; oficial: não se aplica). */
    public function logout(): void;

    /** Confere a autenticidade do webhook (assinatura/segredo). */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** @return list<InboundMessage|StatusUpdate> */
    public function parseWebhook(string $rawBody): array;
}
