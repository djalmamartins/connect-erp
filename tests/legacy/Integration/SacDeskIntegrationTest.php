<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\ScopeTestInput;
use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\SacDesk;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketService;
use Source\Services\Team\TeamPush;

/** Atendimento do SAC pela API do app (ADR 0660): fila por aba, assumir, responder, nota interna, permissão e push. */
final class SacDeskIntegrationTest extends TestCase
{
    private int $alpha;
    private User $agent;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $agentId = $this->createUser(['level' => 10, 'first_name' => 'Bia']);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$agentId, (int)$this->pdo->lastInsertId()]);
        // Quem só acompanha: erp.access sem support.manage.
        $viewerId = $this->createUser(['level' => 2]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Consulta', 'consulta', 20]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = 'erp.access'")->execute([$role]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$viewerId, $role]);
        $scope = new CondominiumScope($this->pdo);
        $scope->grant($agentId, $this->alpha);
        $scope->grant($viewerId, $this->alpha);
        $this->agent = (new User())->findById($agentId);
        $this->viewer = (new User())->findById($viewerId);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        $_GET = [];
        @stream_wrapper_restore('php');
        parent::tearDown();
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function call(User $user, string $action, array $args = [], ?array $body = null, array $query = []): array
    {
        Auth::actingAs($user);
        $_GET = $query;
        if ($body !== null) {
            ScopeTestInput::$body = (string)json_encode($body);
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', ScopeTestInput::class);
        }
        ob_start();
        try {
            $args === [] ? (new SacDesk())->$action() : (new SacDesk())->$action($args);
        } finally {
            $out = (string)ob_get_clean();
            if ($body !== null) {
                stream_wrapper_restore('php');
            }
        }
        return [(int)http_response_code(), (array)json_decode($out, true)];
    }

    private function openTicket(): int
    {
        return (int)(new TicketService($this->pdo))->open(['subject' => 'Lâmpada queimada', 'message' => 'A lâmpada do hall queimou.', 'condominium_id' => $this->alpha,
            'requester_name' => 'Ana', 'requester_email' => 'ana@test.local'], 'erp', $this->viewer)['id'];
    }

    public function testQueueTakeAnswerAndNotes(): void
    {
        $id = $this->openTicket();
        [$status, $body] = $this->call($this->agent, 'index', [], null, ['view' => 'unassigned']);
        self::assertSame(200, $status);
        self::assertSame([$id], array_column($body['data'], 'id'));
        self::assertFalse($body['data'][0]['late']);

        [$status, $body] = $this->call($this->agent, 'take', ['id' => (string)$id], []);
        self::assertSame(200, $status);
        self::assertSame((int)$this->agent->id, $body['data']['ticket']['assigned_to']['id']);
        self::assertSame('in_progress', $body['data']['ticket']['status']);

        [, $body] = $this->call($this->agent, 'index', [], null, ['view' => 'mine']);
        self::assertSame([$id], array_column($body['data'], 'id'));
        [, $body] = $this->call($this->agent, 'summary');
        self::assertSame(1, $body['data']['mine_open']);
        self::assertTrue($body['data']['can_attend']);

        [$status] = $this->call($this->agent, 'answer', ['id' => (string)$id], ['message' => 'Só para a equipe.', 'internal' => true]);
        self::assertSame(201, $status);
        [$status, $body] = $this->call($this->agent, 'answer', ['id' => (string)$id], ['message' => 'Trocamos hoje à tarde.', 'resolve' => true]);
        self::assertSame(201, $status);
        self::assertSame('resolved', $body['data']['status']);

        [, $body] = $this->call($this->agent, 'show', ['id' => (string)$id]);
        self::assertSame([true, false], array_column($body['data']['messages'], 'internal'));
        self::assertNotEmpty($body['data']['events']);
        // Quem só acompanha não vê a nota interna nem o histórico.
        [, $body] = $this->call($this->viewer, 'show', ['id' => (string)$id]);
        self::assertSame(['Trocamos hoje à tarde.'], array_column($body['data']['messages'], 'message'));
        self::assertSame([], $body['data']['events']);
        self::assertFalse($body['data']['can_attend']);
    }

    public function testViewerCannotAttendAndOutsiderGets404(): void
    {
        $id = $this->openTicket();
        [$status, $body] = $this->call($this->viewer, 'take', ['id' => (string)$id], []);
        self::assertSame([403, 'forbidden'], [$status, $body['error']['code']]);
        [$status] = $this->call($this->agent, 'triage', ['id' => '999999'], ['status' => 'closed']);
        self::assertSame(404, $status);
    }

    public function testAssigningToSomeoneElseQueuesPushOnlyForTheSacApp(): void
    {
        $id = $this->openTicket();
        $push = new TeamPush($this->pdo);
        $push->register((int)$this->viewer->id, 'sac', 'ExponentPushToken[viewerviewer1]');
        $push->register((int)$this->agent->id, 'meu-dia', 'ExponentPushToken[agentagentag]');
        [$status] = $this->call($this->agent, 'triage', ['id' => (string)$id], ['assigned_to' => (int)$this->viewer->id]);
        self::assertSame(200, $status);
        $rows = $this->pdo->query('SELECT user_id, app, title, data_json FROM team_push_queue')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame([(int)$this->viewer->id, 'sac'], [(int)$rows[0]['user_id'], $rows[0]['app']]);
        self::assertStringContainsString('é seu agora', $rows[0]['title']);
        self::assertSame(['path' => '/chamados/' . $id], json_decode($rows[0]['data_json'], true));
    }

    public function testOpenByPhoneWithChannelUnitsAndScope(): void
    {
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, block, number, type) VALUES (?, 'B', '302', 'apartamento')")->execute([$this->alpha]);
        $unit = (int)$this->pdo->lastInsertId();
        $label = (string)$this->pdo->query('SELECT label FROM erp_units WHERE id = ' . $unit)->fetchColumn();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $beta = (int)$this->pdo->lastInsertId();
        // Atendente só do Alpha: erp.access e support.manage, sem acesso a todos os condomínios.
        $agentId = $this->createUser(['level' => 5, 'first_name' => 'Caio']);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Atendente', 'atendente', 30]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug IN ('erp.access', 'support.manage')")->execute([$role]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$agentId, $role]);
        (new CondominiumScope($this->pdo))->grant($agentId, $this->alpha);
        $this->agent = (new User())->findById($agentId);

        [$status, $body] = $this->call($this->agent, 'options');
        self::assertSame(200, $status);
        self::assertSame(['Alpha'], array_column($body['data']['condominiums'], 'name'));
        self::assertSame(['telefone', 'whatsapp', 'presencial'], array_column($body['data']['channels'], 'value'));

        [$status, $body] = $this->call($this->agent, 'units', [], null, ['condominium_id' => (string)$this->alpha]);
        self::assertSame([200, [['id' => $unit, 'label' => $label]]], [$status, $body['data']]);
        [$status] = $this->call($this->agent, 'units', [], null, ['condominium_id' => (string)$beta]);
        self::assertSame(403, $status);

        // Só telefone, sem e-mail: vale quando a equipe registra o pedido.
        $input = ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'requester_name' => 'Carla', 'requester_phone' => '(11) 98888-7777',
            'channel' => 'telefone', 'subject' => 'Vazamento no teto da garagem', 'message' => 'Mancha de água na vaga 22, pingando desde cedo.', 'priority' => 'high'];
        [$status, $body] = $this->call($this->agent, 'store', [], $input);
        self::assertSame(201, $status, json_encode($body));
        self::assertMatchesRegularExpression('/^SAC/', $body['data']['protocol']);
        $ticket = $body['data']['ticket'];
        self::assertSame(['helpdesk', 'canal:telefone', 'high', (int)$this->agent->id, $label, 'Carla'],
            [$ticket['origin'], $ticket['tags'], $ticket['priority'], $ticket['assigned_to']['id'], $ticket['unit']['label'], $ticket['requester']['name']]);

        // Outro condomínio, canal inválido, sem contato, e quem só acompanha.
        [$status] = $this->call($this->agent, 'store', [], ['condominium_id' => $beta] + $input);
        self::assertSame(403, $status);
        [$status, $body] = $this->call($this->agent, 'store', [], ['channel' => 'fax'] + $input);
        self::assertSame([422, 'channel'], [$status, array_key_first($body['error']['fields'])]);
        [$status] = $this->call($this->agent, 'store', [], ['requester_phone' => ''] + $input);
        self::assertSame(422, $status);
        [$status] = $this->call($this->viewer, 'store', [], $input);
        self::assertSame(403, $status);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM studio_support_tickets')->fetchColumn());
    }

    public function testViewFiltersArePure(): void
    {
        self::assertSame(['assigned' => '7'], SacDesk::viewFilters('mine', 7));
        self::assertSame(['assigned' => 'unassigned'], SacDesk::viewFilters('unassigned', 7));
        self::assertSame(['due' => 'overdue'], SacDesk::viewFilters('overdue', 7));
        self::assertSame([], SacDesk::viewFilters('all', 7));
        self::assertTrue(SacDesk::late(['status' => 'open', 'due_at' => '2026-11-25 10:00:00'], strtotime('2026-11-26 10:00:00')));
        self::assertFalse(SacDesk::late(['status' => 'resolved', 'due_at' => '2026-11-25 10:00:00'], strtotime('2026-11-26 10:00:00')));
    }
}
