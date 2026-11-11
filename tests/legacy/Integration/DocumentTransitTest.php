<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DocumentTransit;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0215: malotes e avisos de recebimento parados, somente leitura; sem portador, destinatário nem rastreio. */
final class DocumentTransitTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testStuckPouchesAndNotices(): void
    {
        $pouch = ['condominium_id' => $this->condominium, 'courier' => 'Motoboy Fulano', 'contents' => "Notas\nBoletos"];
        $sent = $this->registry->save($this->admin, 'pouches', $pouch + ['sent_on' => '2026-10-20', 'documents_count' => '2'])['id'];
        $received = $this->registry->save($this->admin, 'pouches', $pouch + ['sent_on' => '2026-11-01', 'documents_count' => '4', 'received_on' => '2026-11-02',
            'status' => 'received'])['id'];
        $this->registry->save($this->admin, 'pouches', $pouch + ['sent_on' => '2026-10-01', 'documents_count' => '1', 'received_on' => '2026-10-02',
            'checked_on' => '2026-10-03', 'status' => 'checked']);
        $notice = $this->registry->save($this->admin, 'delivery_notices', ['condominium_id' => $this->condominium, 'recipient' => 'Ciclano da Silva',
            'tracking_code' => 'AR123BR', 'subject' => 'Notificação de multa', 'sent_on' => '2026-11-06'])['id'];
        $this->registry->save($this->admin, 'delivery_notices', ['condominium_id' => $this->condominium, 'recipient' => 'Ciclano da Silva',
            'tracking_code' => 'AR124BR', 'sent_on' => '2026-10-01', 'delivered_on' => '2026-10-04', 'status' => 'delivered']);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new DocumentTransit($this->pdo))->build($this->admin, $this->condominium, '2026-11-10');
        self::assertSame([['pouch', $sent, 'awaiting_receipt', 21, true], ['pouch', $received, 'awaiting_check', 8, true], ['notice', $notice, 'in_transit', 4, false]],
            array_map(static fn (array $i): array => [$i['kind'], $i['id'], $i['stage'], $i['days'], $i['overdue']], $report['items']));
        self::assertSame(2, $report['overdue']);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Ciclano', 'AR123BR', 'multa', 'Boletos'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        self::assertSame(1, (new DocumentTransit($this->pdo))->build($this->admin, $this->condominium, '2026-11-10', 10)['overdue']);
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new DocumentTransit($this->pdo))->build($limited, $this->condominium);
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
