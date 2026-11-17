<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use PDO;
use Source\Services\Privacy\PersonalDataAnonymizer;
use Source\Services\Privacy\PersonalDataExport;

/**
 * Connect Talk no relatório e na anonimização do titular (ADR 0353), no MariaDB de verdade: o telefone do cadastro acha o
 * contato do Talk (com DDI), as conversas, as mensagens e os envios de campanha; anonimizar apaga nome e telefone e mantém
 * as mensagens. Só com acesso a todos os condomínios.
 */
final class PersonalDataTalkIntegrationTest extends TestCase
{
    use ScopeFixtures;

    private const CPF = '52998224725';

    public function testTalkInReportAndAnonymization(): void
    {
        $local = '319' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $phone = '55' . $local;
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Ed. Talk', 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        $unit = $this->scopeRow('erp_units', ['type' => 'apartamento', 'status' => 'active', 'postage_payer' => 'ambos', 'billing_responsible' => 'nao_definido',
            'bill_address' => 'predio', 'bill_email_to' => 'responsavel', 'deleted_at' => null], ['condominium_id' => $condominium]);
        $this->scopeRow('erp_residents', ['relationship' => 'owner', 'status' => 'inactive', 'ends_on' => '2026-01-31', 'deleted_at' => null, 'user_id' => null,
            'name' => 'Titular do Talk', 'document' => self::CPF, 'email' => null, 'phone' => $local], ['condominium_id' => $condominium, 'unit_id' => $unit]);
        $this->pdo->prepare('INSERT INTO talk_contacts (phone, name, erp_snapshot) VALUES (?, ?, ?)')->execute([$phone, 'Titular do Talk', '{"unidade":"101"}']);
        $contact = (int)$this->pdo->lastInsertId();
        $this->scopeRow('talk_contact_notes', ['body' => 'nota da equipe'], ['contact_id' => $contact]);
        $channel = $this->scopeRow('talk_channels', ['name' => 'Canal teste', 'phone' => '5531900000000', 'is_default' => 0]);
        $conversation = $this->scopeRow('talk_conversations', ['protocol' => 'TT-' . $contact, 'status' => 'closed', 'helpdesk_ticket_id' => null, 'assigned_user_id' => null,
            'sector_id' => null, 'closed_by' => null, 'history_from_message_id' => null], ['contact_id' => $contact, 'channel_id' => $channel]);
        $this->pdo->prepare("INSERT INTO talk_messages (conversation_id, direction, type, body) VALUES (?, 'in', 'text', 'Mensagem do titular')")->execute([$conversation]);
        $message = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO talk_outbox (message_id, channel_id, to_phone, status) VALUES (?, ?, ?, 'sent')")->execute([$message, $channel, $phone]);
        $campaign = $this->scopeRow('talk_campaigns', ['name' => 'Campanha teste', 'status' => 'done'], ['channel_id' => $channel]);
        $this->pdo->prepare("INSERT INTO talk_campaign_recipients (campaign_id, contact_id, phone, name, status) VALUES (?, ?, ?, 'Titular do Talk', 'sent')")->execute([$campaign, $contact, $phone]);

        $report = (new PersonalDataExport($this->pdo))->collect([$condominium], self::CPF, null, true);
        foreach (PersonalDataExport::TALK_GROUPS as $group) {
            self::assertCount(1, $report[$group]['rows'], $group);
        }
        self::assertStringNotContainsString('nota da equipe', (string)json_encode($report));

        (new PersonalDataAnonymizer($this->pdo))->apply([$condominium], ['document' => self::CPF, 'reason' => 'Pedido do titular de teste', 'confirm' => true], date('Y-m-d'), true);
        $row = fn (string $sql, int $id): array => (array)$this->pdo->query(sprintf($sql, $id))->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['name' => 'Titular anonimizado #' . $contact, 'phone' => 'anon-' . $contact, 'erp_snapshot' => null], $row('SELECT name, phone, erp_snapshot FROM talk_contacts WHERE id = %d', $contact));
        self::assertSame(['body' => 'Mensagem do titular'], $row('SELECT body FROM talk_messages WHERE id = %d', $message));
        self::assertSame(['to_phone' => ''], $row('SELECT to_phone FROM talk_outbox WHERE message_id = %d', $message));
        self::assertSame('0', (string)$this->pdo->query('SELECT COUNT(*) FROM talk_contact_notes WHERE contact_id = ' . $contact)->fetchColumn());
        self::assertStringStartsWith('anon-', (string)$this->pdo->query('SELECT phone FROM talk_campaign_recipients WHERE campaign_id = ' . $campaign)->fetchColumn());
    }
}
