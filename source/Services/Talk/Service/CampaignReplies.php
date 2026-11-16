<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Jack\Classifier;
use Source\Services\Talk\Support\Db;

/**
 * O que acontece depois do envio em massa, chamado pelo núcleo de conversas:
 * - resposta de quem recebeu a campanha (até 3 dias) vai direto para o setor da campanha;
 * - "SAIR", "PARAR"... tiram a pessoa dos próximos envios em massa (LGPD);
 * - confirmações de entrega/leitura da Meta atualizam o destinatário.
 */
final class CampaignReplies
{
    public const REPLY_WINDOW_HOURS = 72;
    private const OPT_OUT = ['sair', 'parar', 'pare', 'stop', 'cancelar', 'descadastrar', 'remover', 'nao quero receber', 'nao quero mais receber'];

    /** @return array{sector:array{id:int|string, name:string}, campaign:string, text:string}|null */
    public static function replyTo(Db $db, array $contact): ?array
    {
        $r = $db->one(
            'SELECT r.id, r.params, c.name AS campaign, c.sector_id, s.name AS sector_name, t.body
               FROM talk_campaign_recipients r
               JOIN talk_campaigns c ON c.id = r.campaign_id
               JOIN talk_templates t ON t.id = c.template_id
               JOIN talk_sectors s ON s.id = c.sector_id AND s.active = 1
              WHERE r.phone = ? AND r.status IN (\'sent\',\'delivered\',\'read\') AND r.replied_at IS NULL
                AND r.sent_at >= NOW() - INTERVAL ' . self::REPLY_WINDOW_HOURS . ' HOUR
              ORDER BY r.sent_at DESC LIMIT 1',
            [$contact['phone']]
        );
        if (!$r) {
            return null;
        }
        $db->run('UPDATE talk_campaign_recipients SET replied_at = NOW(), contact_id = COALESCE(contact_id, ?) WHERE id = ?', [$contact['id'], $r['id']]);
        return [
            'sector' => ['id' => (int) $r['sector_id'], 'name' => $r['sector_name']],
            'campaign' => $r['campaign'],
            'text' => self::render($r['body'], json_decode((string) $r['params'], true) ?: []),
        ];
    }

    /** Marca quem pediu para sair. Devolve true se o texto é um pedido de saída. */
    public static function checkOptOut(Db $db, int $contactId, string $text): bool
    {
        $t = trim(preg_replace('/[^\p{L}\s]/u', '', Classifier::normalize($text)));
        if (!in_array($t, self::OPT_OUT, true)) {
            return false;
        }
        $db->run('UPDATE talk_contacts SET opt_out_at = COALESCE(opt_out_at, NOW()) WHERE id = ?', [$contactId]);
        return true;
    }

    public static function status(Db $db, string $providerMessageId, string $ack): void
    {
        $order = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        $row = $db->one('SELECT id, status FROM talk_campaign_recipients WHERE provider_message_id = ?', [$providerMessageId]);
        if (!$row) {
            return;
        }
        if ($ack === 'failed') {
            $db->run("UPDATE talk_campaign_recipients SET status = 'failed', error = COALESCE(error, 'A Meta não entregou.') WHERE id = ? AND status IN ('sent')", [$row['id']]);
            return;
        }
        if (isset($order[$ack], $order[$row['status']]) && $order[$ack] > $order[$row['status']]) {
            $db->run('UPDATE talk_campaign_recipients SET status = ? WHERE id = ?', [$ack, $row['id']]);
        }
    }

    public static function render(string $body, array $params): string
    {
        return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($m) => (string) ($params[(int) $m[1] - 1] ?? $m[0]), $body);
    }
}
