<?php
declare(strict_types=1);

namespace Source\Services\Talk\Gateway;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\SendResult;
use Source\Services\Talk\Domain\StatusUpdate;
use Source\Services\Talk\Http\HttpClient;

/**
 * WhatsApp Cloud API (oficial da Meta). Sem risco de banimento; usado para
 * envios em massa (modelos aprovados) e, se quiserem, para atendimento.
 *
 * config: phone_number_id, access_token, app_secret, verify_token, api_version?
 */
final class CloudApiGateway implements WhatsAppGateway
{
    private string $base;

    public function __construct(private array $config, private HttpClient $http)
    {
        $version = $config['api_version'] ?? 'v21.0';
        $this->base = "https://graph.facebook.com/{$version}/{$config['phone_number_id']}/messages";
    }

    public function sendText(string $toPhone, string $text): SendResult
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => true],
        ]);
    }

    /** Indicador de digitação da API oficial: marca a última mensagem como lida e mostra "digitando…" (até ~25 s). */
    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        if ($replyToProviderId === null) {
            return;
        }
        $this->http->postJson($this->base, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $replyToProviderId,
            'typing_indicator' => ['type' => 'text'],
        ], ['Authorization' => 'Bearer ' . $this->config['access_token']]);
    }

    /** Sobe o arquivo para a Meta e envia pelo id (a mídia fica 30 dias lá). */
    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult
    {
        $auth = ['Authorization' => 'Bearer ' . $this->config['access_token']];
        $up = $this->http->postFile($this->graph("{$this->config['phone_number_id']}/media"), ['messaging_product' => 'whatsapp', 'type' => $mime], 'file', $filePath, $mime, $fileName, $auth);
        $mediaId = (json_decode($up['body'], true) ?: [])['id'] ?? null;
        if ($up['status'] >= 300 || !$mediaId) {
            throw new GatewayException("Cloud API: upload da mídia falhou (HTTP {$up['status']})");
        }
        $obj = ['id' => $mediaId];
        if ($type !== 'audio' && $caption !== null && $caption !== '') {
            $obj['caption'] = $caption;
        }
        if ($type === 'document') {
            $obj['filename'] = $fileName;
        }
        return $this->send(['messaging_product' => 'whatsapp', 'to' => $toPhone, 'type' => $type, $type => $obj]);
    }

    public function downloadMedia(string $ref): array
    {
        $auth = ['Authorization' => 'Bearer ' . $this->config['access_token']];
        $meta = $this->http->request('GET', $this->graph(rawurlencode($ref)), null, $auth);
        $info = json_decode($meta['body'], true) ?: [];
        if ($meta['status'] >= 300 || empty($info['url'])) {
            throw new GatewayException("Cloud API: mídia indisponível (HTTP {$meta['status']})");
        }
        $file = $this->http->request('GET', (string) $info['url'], null, $auth);
        if ($file['status'] >= 300 || $file['body'] === '') {
            throw new GatewayException("Cloud API: download da mídia falhou (HTTP {$file['status']})");
        }
        return ['bytes' => $file['body'], 'mime' => $info['mime_type'] ?? null];
    }

    /** API oficial não tem QR Code: "conectar" é conferir se o número e o token respondem. */
    public function connect(?string $webhookUrl = null, ?string $secret = null): array
    {
        return $this->connectionState() + ['qr' => null];
    }

    public function connectionState(): array
    {
        $r = $this->http->request('GET', $this->graph(rawurlencode((string) $this->config['phone_number_id']) . '?fields=display_phone_number,verified_name,quality_rating'),
            null, ['Authorization' => 'Bearer ' . $this->config['access_token']]);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300) {
            return ['state' => 'disconnected', 'detail' => mb_substr((string) ($data['error']['message'] ?? "HTTP {$r['status']}"), 0, 200)];
        }
        $phone = preg_replace('/\D+/', '', (string) ($data['display_phone_number'] ?? '')) ?: null;
        return ['state' => 'connected', 'phone' => $phone, 'detail' => isset($data['quality_rating']) ? 'Qualidade: ' . $data['quality_rating'] : null];
    }

    /**
     * Modelos aprovados na conta (WABA). Precisa do business_account_id na configuração do número.
     * @return list<array{name:string, language:string, category:?string, status:string, body:string}>
     */
    public function listTemplates(): array
    {
        if (empty($this->config['business_account_id'])) {
            throw new GatewayException('Informe o WhatsApp Business Account ID na conexão para buscar os modelos.');
        }
        $url = $this->graph(rawurlencode((string) $this->config['business_account_id']) . '/message_templates?fields=name,language,status,category,components&limit=200');
        $r = $this->http->request('GET', $url, null, ['Authorization' => 'Bearer ' . $this->config['access_token']]);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300) {
            throw new GatewayException('Cloud API: ' . ($data['error']['message'] ?? "HTTP {$r['status']}"));
        }
        $out = [];
        foreach ($data['data'] ?? [] as $t) {
            $body = '';
            foreach ($t['components'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'BODY') {
                    $body = (string) ($c['text'] ?? '');
                }
            }
            $out[] = ['name' => (string) $t['name'], 'language' => (string) ($t['language'] ?? 'pt_BR'), 'category' => $t['category'] ?? null,
                'status' => (string) ($t['status'] ?? 'APPROVED'), 'body' => $body];
        }
        return $out;
    }

    public function logout(): void
    {
        // a API oficial não "desloga": para parar, desative o canal no Talk ou o número na Meta
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/' . ($this->config['api_version'] ?? 'v21.0') . '/' . $path;
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        $components = $params === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $params),
        ]];
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'template',
            'template' => ['name' => $template, 'language' => ['code' => $language], 'components' => $components],
        ]);
    }

    private function send(array $payload): SendResult
    {
        $r = $this->http->postJson($this->base, $payload, ['Authorization' => 'Bearer ' . $this->config['access_token']]);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300 || empty($data['messages'][0]['id'])) {
            $err = $data['error']['message'] ?? "HTTP {$r['status']}";
            throw new GatewayException("Cloud API: $err");
        }
        return new SendResult($data['messages'][0]['id']);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $sig = $headers['x-hub-signature-256'] ?? '';
        if (!str_starts_with($sig, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $this->config['app_secret']);
        return hash_equals($expected, $sig);
    }

    /** Responde ao GET de verificação do webhook (hub.challenge). */
    public function verifySubscription(array $query): ?string
    {
        if (($query['hub_mode'] ?? '') === 'subscribe'
            && hash_equals((string) $this->config['verify_token'], (string) ($query['hub_verify_token'] ?? ''))) {
            return (string) ($query['hub_challenge'] ?? '');
        }
        return null;
    }

    public function parseWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        $out = [];
        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = [];
                foreach ($value['contacts'] ?? [] as $c) {
                    $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
                }
                foreach ($value['messages'] ?? [] as $m) {
                    $type = $m['type'] ?? 'other';
                    $body = match ($type) {
                        'text' => $m['text']['body'] ?? null,
                        'button' => $m['button']['text'] ?? null,
                        'interactive' => $m['interactive']['button_reply']['title']
                            ?? $m['interactive']['list_reply']['title'] ?? null,
                        'image', 'video', 'document' => $m[$type]['caption'] ?? null,
                        'location' => sprintf('%s,%s', $m['location']['latitude'] ?? '', $m['location']['longitude'] ?? ''),
                        default => null,
                    };
                    $media = in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true) ? $m[$type] ?? [] : [];
                    $out[] = new InboundMessage(
                        providerMessageId: (string) $m['id'],
                        fromPhone: (string) $m['from'],
                        type: in_array($type, ['text', 'image', 'audio', 'video', 'document', 'location'], true) ? $type : ($type === 'button' || $type === 'interactive' ? 'text' : 'other'),
                        body: $body,
                        contactName: $names[$m['from']] ?? null,
                        mediaRef: $media['id'] ?? null,
                        mediaMime: $media['mime_type'] ?? null,
                    );
                }
                foreach ($value['statuses'] ?? [] as $s) {
                    $ack = match ($s['status'] ?? '') {
                        'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', default => null,
                    };
                    if ($ack !== null) {
                        $out[] = new StatusUpdate((string) $s['id'], $ack);
                    }
                }
            }
        }
        return $out;
    }
}
