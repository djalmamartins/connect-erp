<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkCharges;
use Source\Services\Talk\TalkHelpdesk;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;
use Source\Support\Middleware\TalkApiAuthenticated;

/**
 * Integração com o Connect Talk (ADR 0308): token de integração, pessoa pelo telefone no escopo do usuário,
 * chamado idempotente pelo protocolo do Talk, transferência e encerramento.
 */
final class TalkIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $unit;
    private int $resident;
    private int $betaResident;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Help Desk', 'support.manage', 'Conteúdo')");
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, block, number, type) VALUES (?, 'A', '101', 'apartamento')")->execute([$this->alpha]);
        $this->unit = (int)$this->pdo->lastInsertId();
        $insert = $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, email, phone, document, ends_on, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$this->alpha, $this->unit, 'A - 101', 'owner', 'João Morador', 'joao@exemplo.test', '31999998888', '12345678909', null, 'active']);
        $this->resident = (int)$this->pdo->lastInsertId();
        $insert->execute([$this->alpha, $this->unit, 'A - 101', 'tenant', 'Antigo Inquilino', null, '31999998888', null, '2020-01-01', 'active']);
        $insert->execute([$this->alpha, null, '', 'resident', 'Inativo', null, '31999998888', null, null, 'inactive']);
        $insert->execute([$this->beta, null, '', 'owner', 'João no Beta', null, '5531999998888', null, null, 'active']);
        $this->betaResident = (int)$this->pdo->lastInsertId();

        $id = $this->createUser(['level' => 5, 'first_name' => 'Connect Talk', 'last_name' => 'Integração']);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->agent = (new User())->findById($id);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        parent::tearDown();
    }

    public function testTokenAuthenticatesTheIntegrationUserOnly(): void
    {
        $tokens = new TalkTokens($this->pdo);
        $created = $tokens->create((int)$this->agent->id);
        self::assertSame((int)$this->agent->id, (int)$tokens->authenticate($created['token'])?->id);
        self::assertSame(hash('sha256', $created['token']), (string)$this->pdo->query('SELECT token_hash FROM erp_integration_tokens WHERE id = ' . $created['id'])->fetchColumn());
        self::assertNotNull($this->pdo->query('SELECT last_used_at FROM erp_integration_tokens WHERE id = ' . $created['id'])->fetchColumn());
        self::assertNull($tokens->authenticate(TalkTokens::newToken()), 'Token que não existe.');

        // Middleware: Bearer válido = usuário só desta requisição; inválido = 401 sem cair na sessão.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $created['token'];
        ob_start();
        self::assertTrue((new TalkApiAuthenticated())->handle(null));
        ob_end_clean();
        self::assertSame((int)$this->agent->id, (int)Auth::user()?->id);
        Auth::actingAs(null);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . TalkTokens::newToken();
        ob_start();
        self::assertFalse((new TalkApiAuthenticated())->handle(null));
        $body = json_decode((string)ob_get_clean(), true);
        self::assertSame('unauthenticated', $body['error']['code']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_throttle_events WHERE scope = 'talk-token'")->fetchColumn());

        // Usuário que perde a permissão ou é desativado derruba o token; revogar também.
        $this->pdo->exec("UPDATE users SET level = 1 WHERE id = " . (int)$this->agent->id);
        Access::clear();
        self::assertNull($tokens->authenticate($created['token']));
        $this->pdo->exec("UPDATE users SET level = 5 WHERE id = " . (int)$this->agent->id);
        Access::clear();
        self::assertTrue($tokens->revoke($created['id'], 'teste'));
        self::assertFalse($tokens->revoke($created['id']));
        self::assertNull($tokens->authenticate($created['token']));
        self::assertNotNull($tokens->list()[0]['revoked_at']);
    }

    public function testTooManyTokenFailuresBlockTheIp(): void
    {
        $tokens = new TalkTokens($this->pdo);
        for ($i = 0; $i < TalkTokens::THROTTLE[0]; $i++) {
            $tokens->fail('10.1.1.1');
        }
        self::assertTrue($tokens->blocked('10.1.1.1'));
        self::assertFalse($tokens->blocked('10.1.1.2'));
    }

    public function testPeopleByPhoneOnlyActiveLinksInScopeAndNoDocument(): void
    {
        $people = (new TalkHelpdesk($this->pdo))->people($this->agent, '5531999998888,31999998888');
        self::assertCount(1, $people, 'Vínculo encerrado, cadastro inativo e o morador do Beta (fora do escopo) não aparecem.');
        self::assertSame($this->resident, $people[0]['id']);
        self::assertSame('João Morador', $people[0]['name']);
        self::assertSame(['id' => $this->alpha, 'name' => 'Alpha'], $people[0]['condominium']);
        self::assertSame(['id' => $this->unit, 'label' => 'A - 101'], $people[0]['unit']);
        self::assertArrayNotHasKey('document', $people[0]);
        // Sem o nono dígito também acha.
        self::assertCount(1, (new TalkHelpdesk($this->pdo))->people($this->agent, '553199998888'));
        self::assertSame([], (new TalkHelpdesk($this->pdo))->people($this->agent, '5511988887777'));
    }

    public function testOpenIsIdempotentAndChecksTheResident(): void
    {
        $talk = new TalkHelpdesk($this->pdo);
        $input = ['talk_protocol' => '20261115-00001', 'subject' => 'WhatsApp 20261115-00001', 'area' => 'technical', 'resident_id' => $this->resident,
            'condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'requester_name' => 'Joãozinho', 'requester_phone' => '5531999998888',
            'conversation_url' => 'https://talk.connect.lab/?c=12'];
        $first = $talk->open($this->agent, $input);
        self::assertTrue($first['created']);
        self::assertMatchesRegularExpression('/^CH\d{6}[0-9A-F]{4}$/', $first['ticket']['protocol']);
        self::assertSame(['open', 'technical', '20261115-00001', $this->alpha, $this->unit], [$first['ticket']['status'], $first['ticket']['area'],
            $first['ticket']['talk_protocol'], $first['ticket']['condominium_id'], $first['ticket']['unit_id']]);
        $row = $this->pdo->query('SELECT origin, requester_name, requester_email, requester_phone, message, created_by FROM studio_support_tickets WHERE id = ' . $first['ticket']['id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('talk', $row['origin']);
        self::assertSame('João Morador', $row['requester_name'], 'O nome vem do cadastro do ERP.');
        self::assertNull($row['requester_email']);
        self::assertSame('5531999998888', $row['requester_phone']);
        self::assertStringContainsString('https://talk.connect.lab/?c=12', $row['message']);
        self::assertSame((int)$this->agent->id, (int)$row['created_by']);

        $again = $talk->open($this->agent, $input);
        self::assertFalse($again['created']);
        self::assertSame($first['ticket']['id'], $again['ticket']['id']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_tickets WHERE origin = 'talk'")->fetchColumn());

        // Contato não identificado: abre com nome e telefone, sem condomínio.
        $unknown = $talk->open($this->agent, ['talk_protocol' => '20261115-00002', 'requester_phone' => '5511988887777', 'message' => 'Oi']);
        self::assertTrue($unknown['created']);
        self::assertNull($unknown['ticket']['condominium_id']);
        self::assertStringStartsWith('Contato do WhatsApp', (string)$this->pdo->query('SELECT requester_name FROM studio_support_tickets WHERE id = ' . $unknown['ticket']['id'])->fetchColumn());

        foreach ([
            'morador fora do escopo' => [['resident_id' => $this->betaResident, 'requester_phone' => '5531999998888'], 'resident_id'],
            'outro condomínio' => [['resident_id' => $this->resident, 'condominium_id' => $this->beta, 'requester_phone' => '5531999998888'], 'condominium_id'],
            'telefone de outra pessoa' => [['resident_id' => $this->resident, 'requester_phone' => '5531977776666'], 'requester_phone'],
            'sem telefone' => [[], 'requester_phone'],
            'condomínio fora do escopo' => [['condominium_id' => $this->beta, 'requester_phone' => '5531999998888'], 'condominium_id'],
        ] as $case => [$extra, $field]) {
            try {
                $talk->open($this->agent, ['talk_protocol' => 'X-' . md5($case)] + $extra);
                self::fail("Devia recusar: {$case}");
            } catch (TicketError $error) {
                self::assertSame($field, $error->field, $case);
            }
        }
        $this->expectException(TicketError::class);
        $talk->open($this->agent, ['requester_phone' => '5531999998888']);
    }

    public function testTransferAndCloseOnlyTalkTickets(): void
    {
        $talk = new TalkHelpdesk($this->pdo);
        $ticket = $talk->open($this->agent, ['talk_protocol' => '20261115-00003', 'area' => 'technical', 'resident_id' => $this->resident, 'requester_phone' => '31999998888'])['ticket'];

        $moved = $talk->transfer($this->agent, $ticket['id'], ['area' => 'financial', 'sector' => 'Financeiro', 'note' => 'Cobrança do reparo', 'talk_user_id' => 2]);
        self::assertSame('financial', $moved['area']);
        $event = $this->pdo->query("SELECT old_value, new_value FROM studio_support_ticket_events WHERE event_type = 'area' AND ticket_id = {$ticket['id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['old_value' => 'technical', 'new_value' => 'financial'], $event);
        $note = $this->pdo->query("SELECT message, is_internal FROM studio_support_ticket_messages WHERE ticket_id = {$ticket['id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$note['is_internal']);
        self::assertStringContainsString('Financeiro', $note['message']);
        self::assertStringContainsString('Cobrança do reparo', $note['message']);
        try {
            $talk->transfer($this->agent, $ticket['id'], ['area' => 'juridico']);
            self::fail('Área inválida.');
        } catch (TicketError $error) {
            self::assertSame('area', $error->field);
        }

        $closed = $talk->close($this->agent, $ticket['id'], ['resolution' => 'Vazamento corrigido pelo zelador.', 'talk_user_id' => 2]);
        self::assertSame('resolved', $closed['status']);
        self::assertNotNull($this->pdo->query("SELECT resolved_at FROM studio_support_tickets WHERE id = {$ticket['id']}")->fetchColumn());
        $messages = (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_messages WHERE ticket_id = {$ticket['id']}")->fetchColumn();
        self::assertSame('resolved', $talk->close($this->agent, $ticket['id'], ['resolution' => 'de novo'])['status']);
        self::assertSame($messages, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_messages WHERE ticket_id = {$ticket['id']}")->fetchColumn(), 'Encerrar de novo não muda nada.');

        // Chamado que não veio do Talk: 404 para o Talk.
        $this->pdo->exec("UPDATE studio_support_tickets SET origin = 'api' WHERE id = {$ticket['id']}");
        try {
            $talk->close($this->agent, $ticket['id'], []);
            self::fail('Chamado de outra origem.');
        } catch (TicketError $error) {
            self::assertSame(404, $error->status());
        }
    }

    /** Boleto registrado de uma conta a receber (ADR 0386). */
    private function charge(int $condominium, ?int $unit, string $due, string $status = 'registered', ?string $paidOn = null, string $competence = '2026-11'): int
    {
        static $chart = [], $bank = [];
        if (!isset($chart[$condominium])) {
            $this->pdo->prepare("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES (?, 'Taxa', 'receita', 'analitica')")->execute(['1.' . $condominium . '.' . random_int(100, 999)]);
            $chart[$condominium] = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number) VALUES (?, 'Movimento', '077', '0001', '1234-5')")->execute([$condominium]);
            $bank[$condominium] = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, unit_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, ?, 'receita', ?, 'Taxa de condomínio', 65000, ?, ?, ?)")->execute([$condominium, $unit, $chart[$condominium], $competence, $due, $paidOn]);
        $entry = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, digitable_line, pix_copy_paste)
            VALUES (?, ?, ?, 'sandbox', ?, ?, 65000, ?, '00190.00009 01234.567890', '000201PIX')")->execute([$condominium, $bank[$condominium], $entry, $status, (string)$entry, $due]);
        return (int)$this->pdo->lastInsertId();
    }

    public function testSecondCopyOnlyWithTheResidentsPhoneAndOpenRegisteredCharges(): void
    {
        $talk = new TalkCharges($this->pdo);
        $due = [$this->charge($this->alpha, $this->unit, '2026-11-10'), $this->charge($this->alpha, $this->unit, '2026-12-10', 'registered', null, '2026-12'),
            $this->charge($this->alpha, $this->unit, '2026-10-10', 'registered', null, '2026-10')];
        $this->charge($this->alpha, $this->unit, '2026-09-01', 'registered', null, '2026-09');   // vencido há mais de 60 dias
        $this->charge($this->alpha, $this->unit, '2026-11-05', 'registered', '2026-11-05');      // já pago
        $this->charge($this->alpha, $this->unit, '2026-11-06', 'cancelled');
        $this->charge($this->alpha, $this->unit, '2026-11-07', 'pending');
        $this->charge($this->alpha, $this->unit, '2026-11-08', 'registered', null, '2026-11');   // quarto do limite de 3? vem antes de 10/11
        $out = $talk->open($this->agent, $this->resident, '+55 (31) 99999-8888', '2026-11-18');
        self::assertCount(TalkCharges::MAX_ITEMS, $out, 'No máximo 3, pelo vencimento.');
        self::assertSame(['2026-10-10', '2026-11-08', '2026-11-10'], array_column($out, 'due_on'));
        self::assertSame([$due[2], $due[0]], [$out[0]['id'], $out[2]['id']]);
        self::assertSame(['Alpha', 65000, '00190.00009 01234.567890', '000201PIX'], [$out[0]['condominium'], $out[0]['amount_cents'], $out[0]['digitable_line'], $out[0]['pix_copy_paste']]);
        self::assertMatchesRegularExpression('~/boletos/\d+$~', $out[0]['portal_url']);
        self::assertCount(3, $talk->open($this->agent, $this->resident, '3199998888', '2026-11-18'), 'Sem o nono dígito também confere.');

        $notFound = function (callable $call): void {
            try {
                $call();
                self::fail('Devia ser 404.');
            } catch (TicketError $error) {
                self::assertSame(404, $error->status());
                self::assertSame('Morador não encontrado.', $error->getMessage(), 'Não diz o motivo.');
            }
        };
        $notFound(fn () => $talk->open($this->agent, $this->resident, '31988887777', '2026-11-18'));        // telefone de outra pessoa
        $notFound(fn () => $talk->open($this->agent, $this->betaResident, '5531999998888', '2026-11-18'));  // outro condomínio
        $notFound(fn () => $talk->open($this->agent, $this->resident + 1, '31999998888', '2026-11-18'));    // inquilino com vínculo encerrado
        $tenant = $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, phone, status) VALUES (?, ?, 'A - 101', 'tenant', 'Inquilina', '31977776666', 'active')");
        $tenant->execute([$this->alpha, $this->unit]);
        $notFound(fn () => $talk->open($this->agent, (int)$this->pdo->lastInsertId(), '31977776666', '2026-11-18'));  // inquilino fica de fora
        $notFound(fn () => $talk->open(null, $this->resident, '31999998888', '2026-11-18'));

        try {
            $talk->open($this->agent, $this->resident, '123', '2026-11-18');
            self::fail('Telefone curto.');
        } catch (TicketError $error) {
            self::assertSame([422, 'phone'], [$error->status(), $error->field]);
        }

        $audits = $this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_bank_charges' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertGreaterThanOrEqual(7, count($audits), 'Toda consulta vai para a auditoria.');
        self::assertStringNotContainsString('99999', implode(' ', $audits), 'Telefone mascarado na auditoria.');
        self::assertStringContainsString('second_copy', $audits[0]);
    }

    public function testSecondCopyIsEmptyWithoutOpenCharges(): void
    {
        self::assertSame([], (new TalkCharges($this->pdo))->open($this->agent, $this->resident, '31999998888', '2026-11-18'));
    }

    public function testChargesRouteIsBehindTheTalkToken(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertStringContainsString('$route->get("/talk/charges", "Talk:charges", null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
    }
}
