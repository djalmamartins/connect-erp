<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DocumentTransit;
use Source\Services\Erp\DocumentTransitSettlement;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0216: baixa em lote de malotes e ARs pelo formulário, tudo ou nada, com prévia e auditoria. */
final class DocumentTransitSettlementTest extends TestCase
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

    public function testSettlesThroughTheForm(): void
    {
        $sent = $this->pouch(['sent_on' => '2026-11-01']);
        $received = $this->pouch(['sent_on' => '2026-11-01', 'received_on' => '2026-11-02', 'status' => 'received']);
        $notice = $this->notice('2026-11-03');
        $returned = $this->notice('2026-11-04');
        $input = ['condominium_id' => $this->condominium, 'on' => '2026-11-09', 'pouch_ids' => [$sent, $received], 'notice_ids' => [$notice]];

        $preview = (new DocumentTransitSettlement($this->pdo))->settle($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 3, 0], [$preview['dry_run'], $preview['settled'], $preview['changed']]);
        self::assertSame(['active', 'received'], $this->pouchStatuses([$sent, $received]), 'dry_run não grava.');
        self::assertStringNotContainsString('Motoboy', (string)json_encode($preview));

        $done = (new DocumentTransitSettlement($this->pdo))->settle($this->admin, $input);
        self::assertSame([['pouch', $sent, 'active', 'received'], ['pouch', $received, 'received', 'checked'], ['notice', $notice, 'active', 'delivered']],
            array_map(static fn (array $d): array => [$d['kind'], $d['id'], $d['status_before'], $d['status_after']], $done['documents']));
        self::assertSame(['received', 'checked'], $this->pouchStatuses([$sent, $received]));
        self::assertSame(['2026-11-09', '2026-11-09'], [$this->value('erp_pouches', $sent, 'received_on'), $this->value('erp_pouches', $received, 'checked_on')]);
        self::assertSame('2026-11-09', $this->value('erp_delivery_notices', $notice, 'delivered_on'));
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE action = 'transit_settle'")->fetchColumn());

        $back = (new DocumentTransitSettlement($this->pdo))->settle($this->admin, ['condominium_id' => $this->condominium, 'on' => '2026-11-09',
            'notice_ids' => [$returned], 'outcome' => 'returned']);
        self::assertSame('returned', $back['documents'][0]['status_after']);
        self::assertNull($this->value('erp_delivery_notices', $returned, 'delivered_on'));
        self::assertSame(['awaiting_check' => 1, 'in_transit' => 0], array_map(static fn (array $s): int => $s['open'],
            array_intersect_key((new DocumentTransit($this->pdo))->build($this->admin, $this->condominium, '2026-11-10')['summary'], ['awaiting_check' => 1, 'in_transit' => 1])));
    }

    public function testAllOrNothing(): void
    {
        $ok = $this->pouch(['sent_on' => '2026-11-01']);
        $late = $this->pouch(['sent_on' => '2026-11-08']);
        try {
            (new DocumentTransitSettlement($this->pdo))->settle($this->admin, ['condominium_id' => $this->condominium, 'on' => '2026-11-05', 'pouch_ids' => [$ok, $late]]);
            self::fail('Recebimento antes do envio passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$late, $exception->errors['_pouch'] ?? null);
        }
        self::assertSame(['active', 'active'], $this->pouchStatuses([$ok, $late]), 'Nada gravado.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE action = 'transit_settle'")->fetchColumn());

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new DocumentTransitSettlement($this->pdo))->settle($limited, ['condominium_id' => $this->condominium, 'on' => '2026-11-09', 'pouch_ids' => [$ok]]);
            self::fail('Baixa fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function pouch(array $data): int
    {
        return $this->registry->save($this->admin, 'pouches', $data + ['condominium_id' => $this->condominium, 'courier' => 'Motoboy Fulano',
            'documents_count' => '2', 'contents' => "Notas\nBoletos"])['id'];
    }

    private function notice(string $sentOn): int
    {
        return $this->registry->save($this->admin, 'delivery_notices', ['condominium_id' => $this->condominium, 'recipient' => 'Ciclano',
            'tracking_code' => 'AR' . random_int(1000, 9999) . 'BR', 'sent_on' => $sentOn])['id'];
    }

    /** @return list<string> */
    private function pouchStatuses(array $ids): array
    {
        return array_map(fn (int $id): string => (string)$this->value('erp_pouches', $id, 'status'), $ids);
    }

    private function value(string $table, int $id, string $column): ?string
    {
        $value = $this->pdo->query("SELECT {$column} FROM {$table} WHERE id = {$id}")->fetchColumn();
        return $value === null || $value === false ? null : substr((string)$value, 0, 10);
    }
}
