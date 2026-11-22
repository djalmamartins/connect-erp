<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Support\TicketProtocol;
use Source\Services\Support\TicketService;
use Source\Services\Talk\TalkHelpdesk;

/**
 * Protocolo no formato do Talk (ADR 0636): SAC para chamado, OCO para ocorrência, sequência própria por dia,
 * número de volta no rollback, número do Talk aproveitado, e busca que acha protocolos novos e antigos.
 */
final class TicketProtocolSequenceTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 5]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testTicketsAndOccurrencesHaveTheirOwnDailySequence(): void
    {
        $today = date('Ymd');
        $first = $this->ticket('Interfone mudo');
        $second = $this->ticket('Portão travando');
        $occurrence = (new RegistryService($this->pdo))->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'subject' => 'Vazamento na garagem',
            'priority' => 'media', 'occurred_on' => date('Y-m-d'), 'description' => 'Água descendo pela rampa da garagem.']);
        $third = $this->ticket('Dúvida do boleto');

        self::assertSame(["SAC{$today}0001", "SAC{$today}0002", "SAC{$today}0003"], [$first, $second, $third]);
        $protocol = $this->pdo->query('SELECT t.protocol FROM erp_occurrences o JOIN studio_support_tickets t ON t.id = o.ticket_id WHERE o.id = ' . (int)$occurrence['id'])->fetchColumn();
        self::assertSame("OCO{$today}0001", $protocol);

        // Outro dia recomeça em 0001.
        $this->pdo->beginTransaction();
        self::assertSame('SAC202612010001', TicketProtocol::next($this->pdo, TicketProtocol::KIND_TICKET, new DateTimeImmutable('2026-12-01')));
        $this->pdo->rollBack();
    }

    public function testRolledBackTicketGivesTheNumberBackAndTakenNumbersAreSkipped(): void
    {
        $today = date('Ymd');
        try {
            (new TicketService($this->pdo))->open(['subject' => 'Vai falhar', 'message' => 'O passo seguinte falha e desfaz tudo.'], 'erp', $this->admin,
                static function (): void { throw new \RuntimeException('falhou'); });
            self::fail('Deveria falhar.');
        } catch (\RuntimeException) {
        }
        self::assertSame("SAC{$today}0001", $this->ticket('Primeiro de verdade'), 'O número do chamado desfeito voltou.');

        // Um número já usado (ex.: veio do Talk) é pulado.
        $this->pdo->prepare('UPDATE studio_support_tickets SET protocol = ? WHERE protocol = ?')->execute(["SAC{$today}0002", "SAC{$today}0001"]);
        $this->pdo->exec('UPDATE studio_support_protocol_sequences SET last_number = 1');
        self::assertSame("SAC{$today}0003", $this->ticket('Pula o 0002'));
    }

    public function testTalkTicketKeepsTheConversationProtocolWhenFree(): void
    {
        $talk = new TalkHelpdesk($this->pdo);
        $base = ['area' => 'technical', 'condominium_id' => $this->condominium, 'requester_name' => 'Joãozinho', 'requester_phone' => '5531999998888'];
        $same = $talk->open($this->admin, $base + ['talk_protocol' => 'SAC202611160008']);
        self::assertSame(['SAC202611160008', 'SAC202611160008'], [$same['ticket']['protocol'], $same['ticket']['talk_protocol']]);

        // Formato antigo do Talk, ou data inválida: o chamado ganha o número do dia.
        $old = $talk->open($this->admin, $base + ['talk_protocol' => '20261115-00001']);
        $bad = $talk->open($this->admin, $base + ['talk_protocol' => 'SAC202613400001']);
        self::assertSame(['SAC' . date('Ymd') . '0001', 'SAC' . date('Ymd') . '0002'], [$old['ticket']['protocol'], $bad['ticket']['protocol']]);
        self::assertFalse($talk->open($this->admin, $base + ['talk_protocol' => 'SAC202611160008'])['created'], 'Mesmo protocolo do Talk: idempotente.');
    }

    public function testSearchFindsNewAndOldProtocolsTypedLoosely(): void
    {
        $new = $this->ticket('Chamado novo');
        $userId = (int)$this->admin->id;
        $this->pdo->prepare("INSERT INTO studio_support_tickets (protocol, subject, message, area, priority, status, condominium_id, requester_id, created_by, due_at)
            VALUES ('CH261109A1B2', 'Chamado antigo', 'Aberto antes do ADR 0636.', 'general', 'medium', 'open', ?, ?, ?, NOW())")->execute([$this->condominium, $userId, $userId]);
        $service = new TicketService($this->pdo);
        $find = fn (string $q): array => array_column($service->listFor($this->admin, ['q' => $q]), 'protocol');
        self::assertSame([$new], $find(strtolower(substr($new, 0, 3)) . ' ' . substr($new, 3, 4) . '-' . substr($new, 7, 4) . '-' . substr($new, 11)));
        self::assertSame(['CH261109A1B2'], $find('ch261109a1b2'));
        self::assertSame(['CH261109A1B2'], $find('antigo'));
    }

    private function ticket(string $subject): string
    {
        return (new TicketService($this->pdo))->open(['subject' => $subject, 'message' => 'Descrição suficiente do chamado.', 'condominium_id' => $this->condominium], 'erp', $this->admin)['protocol'];
    }
}
