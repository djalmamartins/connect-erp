<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\Support\TicketAttachmentAccess;

/** Anexo de chamado só para quem pode ver o chamado (ADR 0339). */
final class TicketAttachmentAccessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tka-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/files/2026/11', 0700, true);
        mkdir($this->root . '/storage/images/2026/11', 0700, true);
        mkdir($this->root . '/storage/private', 0700, true);
        file_put_contents($this->root . '/storage/files/2026/11/ticket-1-aa.pdf', '%PDF');
        file_put_contents($this->root . '/storage/images/2026/11/public-ticket-1-bb.jpg', 'jpg');
        file_put_contents($this->root . '/storage/private/segredo.txt', 'x');
        file_put_contents($this->root . '/fora.txt', 'x');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAllowedRule(): void
    {
        $public = ['message_id' => 5, 'is_internal' => 0];
        $internal = ['message_id' => 6, 'is_internal' => 1];
        $loose = ['message_id' => null, 'is_internal' => null];
        self::assertTrue(TicketAttachmentAccess::allowed($internal, true, false));
        self::assertTrue(TicketAttachmentAccess::allowed($loose, true, false));
        self::assertTrue(TicketAttachmentAccess::allowed($public, false, true));
        self::assertFalse(TicketAttachmentAccess::allowed($public, false, false));
        self::assertFalse(TicketAttachmentAccess::allowed($internal, false, true));
        self::assertFalse(TicketAttachmentAccess::allowed($loose, false, true));
    }

    public function testResolveStaysInsideFilesAndImages(): void
    {
        $access = new TicketAttachmentAccess(new PDO('sqlite::memory:'), $this->root . '/storage');
        self::assertNotNull($access->resolve('files/2026/11/ticket-1-aa.pdf'));
        self::assertNotNull($access->resolve('/images/2026/11/public-ticket-1-bb.jpg'));
        self::assertNull($access->resolve('private/segredo.txt'));
        self::assertNull($access->resolve('files/../../fora.txt'));
        self::assertNull($access->resolve('files/2026/11/nao-existe.pdf'));
        self::assertNull($access->resolve('files/2026'));
    }

    public function testFindAppliesTicketVisibilityAndInternalNotes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE studio_support_ticket_messages (id INTEGER PRIMARY KEY, ticket_id INTEGER, is_internal INTEGER)');
        $pdo->exec('CREATE TABLE studio_support_ticket_attachments (id INTEGER PRIMARY KEY, ticket_id INTEGER, message_id INTEGER NULL, file_path TEXT, original_name TEXT)');
        $pdo->exec("INSERT INTO studio_support_ticket_messages VALUES (10, 1, 0), (11, 1, 1)");
        $pdo->exec("INSERT INTO studio_support_ticket_attachments VALUES
            (1, 1, 10, 'files/2026/11/ticket-1-aa.pdf', 'contrato.pdf'),
            (2, 1, 11, 'files/2026/11/ticket-1-aa.pdf', 'nota.pdf'),
            (3, 1, NULL, 'images/2026/11/public-ticket-1-bb.jpg', 'foto.jpg'),
            (4, 1, 10, 'private/segredo.txt', 'x.txt')");
        $access = new TicketAttachmentAccess($pdo, $this->root . '/storage');
        $user = new User();
        $user->id = 9;
        $sees = static fn (User $u, int $ticket): bool => $ticket === 1;
        $blind = static fn (User $u, int $ticket): bool => false;

        self::assertSame('contrato.pdf', $access->find($user, 1, $sees, false)['name'] ?? null);
        self::assertNull($access->find($user, 1, $blind, false), 'chamado de outro condomínio');
        self::assertNull($access->find($user, 2, $sees, false), 'nota interna');
        self::assertNull($access->find($user, 3, $sees, false), 'anexo do widget sem mensagem');
        self::assertSame('nota.pdf', $access->find($user, 2, $blind, true)['name'] ?? null);
        self::assertSame('foto.jpg', $access->find($user, 3, $blind, true)['name'] ?? null);
        self::assertNull($access->find($user, 4, $sees, true), 'fora de files/images');
        self::assertNull($access->find($user, 99, $sees, true));
    }
}
