<?php
declare(strict_types=1);

namespace Source\Services\Talk\Gateway;

use Source\Services\Talk\Domain\SendResult;

/** Canal de laboratório: não fala com o WhatsApp, só finge que enviou. */
final class LabGateway implements WhatsAppGateway
{
    public function sendText(string $toPhone, string $text): SendResult
    {
        return new SendResult('LAB-' . bin2hex(random_bytes(8)));
    }

    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult
    {
        return $this->sendText($toPhone, (string) $caption);
    }

    public function downloadMedia(string $ref): array
    {
        throw new GatewayException('Laboratório: o simulador já grava o arquivo direto.');
    }

    /** Laboratório: QR de mentira; "Simular leitura" na tela conecta. */
    public function connect(?string $webhookUrl = null, ?string $secret = null): array
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" shape-rendering="crispEdges"><rect width="29" height="29" fill="#fff"/>';
        $seed = crc32((string) microtime(true));
        for ($y = 0; $y < 29; $y++) {
            for ($x = 0; $x < 29; $x++) {
                $finder = ($x < 8 && $y < 8) || ($x > 20 && $y < 8) || ($x < 8 && $y > 20);
                $on = $finder ? (max(abs(($x % 21 > 7 ? $x - 21 : $x) - 3), abs(($y > 20 ? $y - 21 : $y) - 3)) !== 2 && max(abs(($x % 21 > 7 ? $x - 21 : $x) - 3), abs(($y > 20 ? $y - 21 : $y) - 3)) < 4)
                    : ((($x * 7 + $y * 13 + $seed) ^ ($x * $y)) % 3 === 0);
                if ($on) {
                    $svg .= "<rect x=\"$x\" y=\"$y\" width=\"1\" height=\"1\"/>";
                }
            }
        }
        return ['state' => 'connecting', 'qr' => 'data:image/svg+xml;base64,' . base64_encode($svg . '</svg>')];
    }

    public function connectionState(): array
    {
        return ['state' => 'connected'];
    }

    public function logout(): void
    {
    }

    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        // laboratório: o simulador mostra "digitando…" lendo a talk_outbox
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        return $this->sendText($toPhone, $fallbackText);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false; // laboratório não recebe webhook; use o simulador
    }

    public function parseWebhook(string $rawBody): array
    {
        return [];
    }
}
