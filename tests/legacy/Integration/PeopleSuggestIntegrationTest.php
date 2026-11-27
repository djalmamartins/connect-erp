<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Directory\AccessDeniedSuggest;
use Source\Services\Directory\PeopleSuggest;

/** Sugestão de pessoa e e-mail ao digitar no MariaDB (ADR 0687): escopo por condomínio, permissões e LGPD. */
final class PeopleSuggestIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $id = $this->createUser(['first_name' => 'Carla', 'last_name' => 'Operadora', 'level' => 5]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->staff = (new User())->findById($id);
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    private function resident(int $condominium, string $name, ?string $email, string $unit = '101', string $status = 'active', ?string $document = null): int
    {
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_label, relationship, name, document, email, phone, status) VALUES (?, ?, 'owner', ?, ?, ?, '11999990000', ?)")
            ->execute([$condominium, $unit, $name, $document, $email, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function service(bool $residents = true): PeopleSuggest
    {
        return new PeopleSuggest($this->pdo, null, static fn (string $p): bool => $p === 'erp.access' || ($residents && $p === 'erp.residents.view'));
    }

    public function testOnlyResidentsOfTheScopeAndNoPersonalDataBeyondTheField(): void
    {
        $this->resident($this->alpha, 'Mariana Lopes', 'mariana@ex.com', '101', 'active', '12345678901');
        $this->resident($this->alpha, 'Mário Inativo', 'mario@ex.com', '102', 'inactive');
        $this->resident($this->beta, 'Mariana Outra', 'outra@ex.com');
        $account = $this->createUser(['first_name' => 'Mariana', 'last_name' => 'Lopes', 'email' => 'mariana@ex.com']);

        $found = $this->service()->search($this->staff, ['q' => 'mar', 'sources' => 'residents']);
        self::assertSame(['Mariana Lopes'], array_column($found, 'name'), 'Nem outro condomínio nem cadastro inativo.');
        self::assertSame([null, $account, 'Proprietário · 101 · Alpha'], [$found[0]['email'], $found[0]['account_id'], $found[0]['context']]);
        $json = (string)json_encode($found);
        foreach (['12345678901', '11999990000', 'mariana@ex.com'] as $secret) {
            self::assertStringNotContainsString($secret, $json, $secret);
        }

        $email = $this->service()->search($this->staff, ['q' => 'mariana@', 'kind' => 'email', 'sources' => 'residents']);
        self::assertSame(['mariana@ex.com'], array_column($email, 'email'), 'Campo de e-mail casa e devolve o e-mail.');
        self::assertSame([], $this->service()->search($this->staff, ['q' => 'mar', 'sources' => 'residents', 'condominium_id' => $this->beta]),
            'Condomínio fora do escopo pedido à mão = nada.');
        $audits = $this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'people_suggest' AND action = 'search'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(3, $audits, 'Cada busca na auditoria.');
        foreach ($audits as $context) {
            self::assertStringNotContainsStringIgnoringCase('mar', (string)$context, 'Auditoria sem o texto buscado.');
        }
    }

    public function testTeamAndPermissions(): void
    {
        $this->createUser(['first_name' => 'Marta', 'last_name' => 'Equipe', 'email' => 'marta@connect.lab', 'level' => 5]);
        $this->createUser(['first_name' => 'Marta', 'last_name' => 'Moradora', 'email' => 'marta@ex.com', 'level' => 1]);
        $this->resident($this->alpha, 'Martinho Dono', 'martinho@ex.com');
        // Operador da ADR 0682: users.level 2, mas o papel entra no ERP.
        $operator = $this->createUser(['first_name' => 'Martim', 'last_name' => 'Operador', 'email' => 'martim@connect.lab', 'level' => 2]);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Operador', 'operator', 30)");
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO access_permissions (name, slug, group_name) VALUES ('ERP', 'erp.access', 'ERP')");
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, (int)$this->pdo->lastInsertId()]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$operator, $role]);

        $team = $this->service(false)->search($this->staff, ['q' => 'mart']);
        self::assertSame([['Marta Equipe', 'team', 'Equipe Connect'], ['Martim Operador', 'team', 'Equipe Connect']],
            array_map(static fn (array $i): array => [$i['name'], $i['source'], $i['context']], $team),
            'Sem erp.residents.view só a equipe; conta de morador nunca entra pela equipe.');
        $both = $this->service()->search($this->staff, ['q' => 'mart', 'with' => 'email']);
        self::assertSame(['Marta Equipe', 'Martim Operador', 'Martinho Dono'], array_column($both, 'name'));
        self::assertSame('marta@connect.lab', $both[0]['email']);

        $resident = (new User())->findById($this->createUser(['level' => 1]));
        $this->expectException(AccessDeniedSuggest::class);
        (new PeopleSuggest($this->pdo, null, static fn (): bool => false))->search($resident, ['q' => 'mart']);
    }

    public function testChosenAccountIsCheckedOnTheServer(): void
    {
        $this->resident($this->alpha, 'Bruno Alpha', 'bruno@ex.com');
        $this->resident($this->beta, 'Bia Beta', 'bia@ex.com');
        $bruno = $this->createUser(['email' => 'bruno@ex.com']);
        $bia = $this->createUser(['email' => 'bia@ex.com']);
        $colleague = $this->createUser(['email' => 'colega@connect.lab', 'level' => 6]);
        $service = $this->service();
        self::assertTrue($service->accountAllowed($this->staff, $bruno, 'Bruno@ex.com'));
        self::assertFalse($service->accountAllowed($this->staff, $bruno, 'outro@ex.com'), 'E-mail diferente do da conta.');
        self::assertFalse($service->accountAllowed($this->staff, $bia, 'bia@ex.com'), 'Morador de outro condomínio.');
        self::assertTrue($service->accountAllowed($this->staff, $colleague, 'colega@connect.lab'));
        self::assertTrue($service->isTeam($colleague));
        self::assertFalse($service->isTeam($bruno));
    }
}
