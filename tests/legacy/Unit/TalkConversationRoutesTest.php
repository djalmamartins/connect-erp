<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Talk\TalkConversation;

/** Conversa dos chamados do Talk (ADR 0634): cursor, nome de quem respondeu, rotas e OpenAPI. */
final class TalkConversationRoutesTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testCursorAndFirstName(): void
    {
        self::assertSame(0, TalkConversation::cursor(null));
        self::assertSame(0, TalkConversation::cursor('-3'));
        self::assertSame(0, TalkConversation::cursor('12 OR 1=1'));
        self::assertSame(42, TalkConversation::cursor('42'));
        self::assertSame('Carla', TalkConversation::firstName('  Carla Atendente Souza'));
        self::assertSame('Equipe Connect', TalkConversation::firstName(null));
        self::assertSame('Equipe Connect', TalkConversation::firstName('   '));
    }

    public function testRoutesAndOpenApiAreWired(): void
    {
        $index = $this->file('index.php');
        $mw = ', null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);';
        foreach (['get("/talk/tickets", "Talk:feed"', 'get("/talk/tickets/{id}", "Talk:ticket"', 'post("/talk/tickets/{id}/messages", "Talk:message"'] as $route) {
            self::assertStringContainsString('$route->' . $route . $mw, $index);
        }
        $openapi = $this->file('docs/api/openapi.yaml');
        foreach (['operationId: talkTicketFeed', '  /talk/tickets/{id}:', '  /talk/tickets/{id}/messages:', 'talk_message_id'] as $needle) {
            self::assertStringContainsString($needle, $openapi);
        }
        self::assertGreaterThanOrEqual(6, substr_count($this->file('source/Controllers/Api/V1/Talk.php'), "agent('tickets.write')"));
    }
}
