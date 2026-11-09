<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Environments;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/** Alternador de ambientes e regras de abertura de chamado sem banco (ADR 0034). */
final class EnvironmentsTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    public function testHostRoles(): void
    {
        self::assertSame('erp', Environments::hostRole('erp.connect.lab'));
        self::assertSame('studio', Environments::hostRole('studio.connect.lab:8443'));
        self::assertSame('main', Environments::hostRole('connect.lab'));
        self::assertSame('main', Environments::hostRole('localhost'));
        self::assertSame('main', Environments::hostRole('www.connectcondominios.com.br'));
    }

    public function testTargetsWithDedicatedHosts(): void
    {
        self::assertSame(['host' => 'studio.connect.lab', 'path' => '/helpdesk/tickets'], Environments::target('helpdesk', 'erp.connect.lab'));
        self::assertSame(['host' => 'studio.connect.lab', 'path' => '/studio/dash'], Environments::target('studio', 'erp.connect.lab'));
        self::assertSame(['host' => 'erp.connect.lab', 'path' => '/dashboard'], Environments::target('erp', 'studio.connect.lab'));
        self::assertSame(['host' => 'erp.connect.lab', 'path' => '/dashboard'], Environments::target('erp', 'erp.connect.lab'));
        self::assertSame(['host' => 'www.connect.lab', 'path' => '/'], Environments::target('site', 'erp.connect.lab'));
        self::assertSame(['host' => 'studio.connect.lab:8443', 'path' => '/helpdesk/tickets'], Environments::target('helpdesk', 'erp.connect.lab:8443'), 'A porta do host acompanha.');
    }

    public function testTargetsOnSingleHost(): void
    {
        self::assertSame(['host' => 'localhost', 'path' => '/erp/dashboard'], Environments::target('erp', 'localhost'));
        self::assertSame(['host' => 'localhost', 'path' => '/helpdesk/tickets'], Environments::target('helpdesk', 'localhost'));
        self::assertSame(['host' => 'connect.lab', 'path' => '/studio/dash'], Environments::target('studio', 'connect.lab'));
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Environments::target('marketplace', 'erp.connect.lab');
    }

    public function testHrefUsesHandoffOnlyAcrossHosts(): void
    {
        $_SERVER['HTTP_HOST'] = 'erp.connect.lab';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        self::assertStringEndsWith('erp.connect.lab/ambiente/helpdesk', Environments::href('helpdesk'));
        self::assertStringEndsWith('erp.connect.lab/ambiente/studio', Environments::href('studio'));
        self::assertStringEndsWith('erp.connect.lab/dashboard', Environments::href('erp'));
        self::assertSame('http://www.connect.lab/', Environments::href('site'), 'O site não precisa de sessão.');

        $_SERVER['HTTP_HOST'] = 'studio.connect.lab';
        self::assertStringEndsWith('studio.connect.lab/helpdesk/tickets', Environments::href('helpdesk'));
        self::assertStringEndsWith('studio.connect.lab/ambiente/erp', Environments::href('erp'));
        self::assertSame('http://studio.connect.lab/helpdesk/tickets?ticket=7', Environments::url('helpdesk', '/helpdesk/tickets?ticket=7', 'erp.connect.lab'));
    }

    public function testEveryLoggedUserSeesErpStudioAndHelpDesk(): void
    {
        $_SERVER['HTTP_HOST'] = 'erp.connect.lab';
        self::assertSame([], Environments::menu(null, 'erp'), 'Sem sessão não há alternador.');
        $user = new User();
        $user->id = 99;
        $user->level = 1;
        $keys = array_column(Environments::menu($user, 'erp'), 'key');
        foreach (['studio', 'erp', 'helpdesk', 'site'] as $key) {
            self::assertContains($key, $keys);
            self::assertTrue(Environments::canAccess($user, $key));
        }
        $current = array_values(array_filter(Environments::menu($user, 'erp'), static fn (array $item): bool => $item['current']));
        self::assertSame(['erp'], array_column($current, 'key'));
        self::assertFalse(Environments::canAccess($user, 'marketplace'));
        self::assertSame('support.manage', Environments::permission('helpdesk'), 'Gancho da permissão por usuário já mapeado.');
    }

    public function testTicketNormalizationRules(): void
    {
        $ok = TicketService::normalize(['subject' => ' Vazamento <b>na</b> garagem ', 'message' => 'Água descendo pela parede do bloco B.', 'priority' => 'urgent', 'condominium_id' => '3', 'unit_id' => '9', 'requester_phone' => '(11) 99999-0000<script>'], 'erp');
        self::assertSame('Vazamento na garagem', $ok['subject']);
        self::assertSame('urgent', $ok['priority']);
        self::assertSame(3, $ok['condominium_id']);
        self::assertSame(9, $ok['unit_id']);
        self::assertSame('erp', $ok['origin']);
        self::assertSame('(11) 99999-0000', $ok['requester_phone']);
        self::assertSame('medium', TicketService::normalize(['subject' => 'Assunto', 'message' => 'Mensagem longa o bastante', 'priority' => 'x'], 'api')['priority']);

        $cases = [
            [['subject' => 'Oi', 'message' => 'Mensagem longa o bastante'], 'erp', 'subject'],
            [['subject' => 'Assunto', 'message' => 'curta'], 'erp', 'message'],
            [['subject' => 'Assunto', 'message' => 'Mensagem longa o bastante', 'unit_id' => 4], 'erp', 'condominium_id'],
            [['subject' => 'Assunto', 'message' => 'Mensagem longa o bastante', 'requester_email' => 'não-é-email'], 'erp', 'requester_email'],
            [['subject' => 'Assunto', 'message' => 'Mensagem longa o bastante'], 'marketplace', 'origin'],
        ];
        foreach ($cases as [$input, $origin, $field]) {
            try {
                TicketService::normalize($input, $origin);
                self::fail("Deveria recusar {$field}.");
            } catch (TicketError $error) {
                self::assertSame($field, $error->field);
            }
        }
        self::assertSame(24, TicketService::slaHours('urgent'));
        self::assertSame(72, TicketService::slaHours('qualquer'));
    }
}
