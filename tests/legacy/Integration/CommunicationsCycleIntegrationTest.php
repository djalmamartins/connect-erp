<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Comunicação de ponta a ponta (ciclo 28, ADR 0059 e 0065), encadeada com o financeiro e a fila de
 * avisos: comunicado geral respeita a recusa → aviso de cobrança das contas vencidas só para quem
 * autorizou → sem aviso repetido na semana → agendado não sai antes da hora e cancelado não sai →
 * endereços batem com o que saiu. Tudo pela fila, fora de produção só para a caixa de teste. No fim,
 * um usuário de outro condomínio não alcança nada.
 */
final class CommunicationsCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    /** @var list<array{string, string, string, string}> assunto, corpo, e-mail, nome */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
    }

    public function testAnnouncementCollectionScheduleAddressesAndScope(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        // 101 aceita tudo; 102 recusou comunicado mas autorizou cobrança; 103 recebe comunicado mas não autorizou cobrança.
        $people = ['101' => ['ana@morador.test', 0, 1], '102' => ['bia@morador.test', 1, 1], '103' => ['caio@morador.test', 0, 0]];
        $units = [];
        foreach ($people as $number => [$email, $optOut, $billing]) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => (string)$number, 'type' => 'apartamento'])['id'];
            $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $units[$number], 'relationship' => 'owner',
                'name' => "Morador {$number}", 'document' => self::validCpf(), 'email' => $email]);
            $this->pdo->prepare('UPDATE erp_residents SET notices_opt_out = ?, billing_by_email = ? WHERE email = ?')->execute([$optOut, $billing, $email]);
            $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $fee, 'unit_id' => $units[$number],
                'description' => "Taxa {$number}", 'amount_cents' => '500,00', 'competence' => date('Y-m', strtotime('-2 months')), 'due_on' => date('Y-m-d', strtotime('-40 days'))]);
        }
        $announcements = new Announcements($this->pdo);

        // 1. Comunicado geral para todas: a 102 recusou, então saem dois e-mails, os dois para a caixa de teste.
        $general = $announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Obras na fachada', 'body' => "Andaimes de 20 a 30.\nObrigado."]);
        self::assertSame(3, $general['units_count']);
        $summary = $this->dispatcher()->dispatch();
        self::assertSame(['sandbox', 2, 1], [$summary['mode'], $summary['sent'], $summary['cancelled']]);
        self::assertSame(['caixa@mailpit.local'], array_values(array_unique(array_column($this->queued, 2))), 'Nunca o e-mail do morador.');
        self::assertSame(['[TESTE] Obras na fachada'], array_values(array_unique(array_column($this->queued, 0))));
        self::assertSame(['sent' => 2, 'cancelled' => 1], array_intersect_key($announcements->find($this->admin, $general['id'])['delivery'], ['sent' => 0, 'cancelled' => 0]));

        // 2. Aviso de cobrança das três vencidas: só 101 e 102 autorizaram cobrança por e-mail.
        $this->queued = [];
        $reminder = $announcements->collection($this->admin, ['condominium_id' => $this->alpha, 'min_days_late' => 30]);
        self::assertSame([3, 0], [$reminder['units_count'], $reminder['skipped_recent']]);
        $summary = $this->dispatcher()->dispatch();
        self::assertSame([2, 1], [$summary['sent'], $summary['cancelled']]);
        foreach ($this->queued as [, $body]) {
            self::assertStringContainsString('R$ 500,00', $body);
        }

        // 3. De novo na mesma semana: 101 e 102 já foram avisadas. A 103 não recebeu nada (ninguém autorizou), então volta ao lote.
        $again = $announcements->collection($this->admin, ['condominium_id' => $this->alpha, 'min_days_late' => 30]);
        self::assertSame([1, 2], [$again['units_count'], $again['skipped_recent']]);
        $this->dispatcher()->dispatch();

        // 4. Agendado não sai antes da hora; cancelado não sai nem quando a hora chega.
        $this->queued = [];
        $scheduled = $announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Assembleia', 'body' => 'Assembleia geral no salão.',
            'audience' => 'units', 'unit_ids' => [$units['101']], 'send_at' => date('Y-m-d H:i', strtotime('+1 day'))]);
        self::assertSame(0, $this->dispatcher()->dispatch()['processed']);
        self::assertSame(1, $announcements->list($this->admin, $this->alpha, ['scheduled' => 1])['total']);
        self::assertSame(1, $announcements->cancel($this->admin, $scheduled['id'], 'Assembleia adiada')['stopped']);
        $this->pdo->exec('UPDATE erp_notification_outbox SET available_at = NOW()');
        self::assertSame(0, $this->dispatcher()->dispatch()['processed']);
        self::assertSame([], $this->queued);

        // 5. Endereços batem com o que saiu: 102 sem comunicado, 103 sem cobrança; e-mail mascarado.
        $addresses = $announcements->addresses($this->admin, $this->alpha);
        self::assertSame(['units' => 3, 'without_notices' => 1, 'without_billing' => 1], $addresses['totals']);
        self::assertSame('a**@morador.test', $addresses['data'][0]['people'][0]['email']);
        self::assertSame(4, $announcements->list($this->admin, $this->alpha)['total']);

        // 6. Usuário só do Beta não alcança nada do Alpha.
        $outsider = $this->user([$this->beta]);
        foreach ([
            fn () => $announcements->announce($outsider, ['condominium_id' => $this->alpha, 'subject' => 'Fora do escopo', 'body' => 'Não deveria sair.']),
            fn () => $announcements->collection($outsider, ['condominium_id' => $this->alpha]),
            fn () => $announcements->find($outsider, (int)$general['id']),
            fn () => $announcements->cancel($outsider, (int)$reminder['id'], 'Fora do escopo'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $announcements->list($outsider)['total']);
        self::assertSame([], $announcements->addresses($outsider, $this->alpha)['data']);
        self::assertSame(0, $this->dispatcher()->list($outsider)['total'], 'Avisos de outro condomínio não aparecem na fila.');
        self::assertSame('active', $announcements->find($this->admin, (int)$reminder['id'])['status'], 'A tentativa de fora não cancelou.');
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher($this->pdo, 'development', 'production', 'caixa@mailpit.local', function (string $subject, string $body, string $email, string $name): int {
            $this->queued[] = [$subject, $body, $email, $name];
            return count($this->queued);
        });
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
