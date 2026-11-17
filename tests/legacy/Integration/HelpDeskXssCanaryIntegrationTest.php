<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Studio\Studio;

/**
 * XSS no Help Desk (ADR 0345): um chamado com texto com HTML no assunto, na descrição, no nome e e-mail de quem pediu, na
 * conversa, na nota interna e no nome do anexo. A fila e a ficha do chamado (renderizadas no servidor) só podem mostrar o
 * texto escapado.
 */
final class HelpDeskXssCanaryIntegrationTest extends TestCase
{
    use ScopeFixtures;

    private const CANARY = '"\'></script><i data-xss>x';
    private const MARK = '<i data-xss>';

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testQueueAndTicketEscapeStoredText(): void
    {
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([self::CANARY, 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 5, 'first_name' => self::CANARY, 'last_name' => self::CANARY]);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Dev', 'developer', 10)");
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'developer'")->execute([$userId]);
        $this->pdo->exec('UPDATE settings SET access_studio = 1');
        $_SESSION['authUser'] = $userId;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $texts = static fn (array $columns): array => array_fill_keys($columns, self::CANARY);
        $ticket = $this->scopeRow('studio_support_tickets', $texts(['subject', 'description', 'requester_name', 'requester_email', 'category', 'tags', 'team'])
            + ['status' => 'open', 'priority' => 'medium', 'area' => 'administrativo', 'origin' => 'erp'],
            ['condominium_id' => $condominium, 'created_by' => $userId, 'requester_id' => $userId, 'user_id' => $userId]);
        foreach ([0, 1] as $internal) {
            $message = $this->scopeRow('studio_support_ticket_messages', ['message' => self::CANARY, 'is_internal' => $internal],
                ['ticket_id' => $ticket, 'user_id' => $userId]);
            $this->scopeRow('studio_support_ticket_attachments', ['original_name' => self::CANARY, 'file_path' => 'files/2026/11/ticket-x.pdf'],
                ['ticket_id' => $ticket, 'message_id' => $message, 'user_id' => $userId]);
        }
        $this->scopeRow('studio_support_ticket_events', ['event_type' => 'note', 'old_value' => self::CANARY, 'new_value' => self::CANARY],
            ['ticket_id' => $ticket, 'user_id' => $userId]);
        $this->scopeRow('studio_support_templates', ['title' => self::CANARY, 'body' => self::CANARY], ['created_by' => $userId]);

        foreach (['fila' => [], 'ficha' => ['ticket' => (string)$ticket]] as $name => $query) {
            \Source\Support\Access::clear();
            $_GET = $query;
            ob_start();
            (new Studio())->tickets([]);
            $html = (string)ob_get_clean();
            self::assertStringContainsString(htmlspecialchars(self::MARK, ENT_QUOTES, 'UTF-8'), $html, "A $name não mostrou o chamado de teste.");
            self::assertStringNotContainsString(self::MARK, $html, "Texto gravado saiu sem escapar na $name do Help Desk.");
        }
    }
}
