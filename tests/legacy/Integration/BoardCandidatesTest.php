<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BoardCandidates;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/** ADR 0683: síndico e conselho escolhidos entre os moradores do prédio e os síndicos profissionais. */
final class BoardCandidatesTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->alpha = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'Alpha'")->fetchColumn();
        $this->beta = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'Beta'")->fetchColumn();
        $this->registry = new RegistryService($this->pdo);
    }

    public function testCandidatesLinkAndRules(): void
    {
        $user = $this->user([$this->alpha], ['erp.residents.view']);
        $unit = $this->registry->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $ana = $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'owner',
            'name' => 'Ana Moradora', 'document' => '529.982.247-25', 'email' => 'ana@example.invalid', 'phone' => '11999990000'])['id'];
        $gone = $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_label' => '202', 'relationship' => 'tenant',
            'name' => 'Bruno Saiu', 'status' => 'inactive'])['id'];
        $pro = $this->registry->save($user, 'professional_managers', ['name' => 'Gestão Síndica Ltda', 'document' => '11.222.333/0001-81',
            'email' => 'contato@example.invalid', 'phone' => '1133334444', 'contact_name' => 'Carla'])['id'];

        $list = (new BoardCandidates($this->pdo))->list($user, (string)$this->alpha);
        self::assertTrue($list['residents_allowed']);
        self::assertSame([['Ana Moradora', $unit, '101', 'Proprietário', '529.982.247-25']], array_map(static fn (array $r): array => [$r['name'], $r['unit_id'], $r['unit_label'], $r['relationship_label'], $r['document_formatted']], $list['residents']), 'Só ativos.');
        self::assertSame([['Gestão Síndica Ltda', 'Carla', 'contato@example.invalid']], array_map(static fn (array $r): array => [$r['name'], $r['contact_name'], $r['email']], $list['professionals']));

        // Vínculo conferido no servidor: morador ativo do mesmo condomínio, profissional só síndico/subsíndico, nunca os dois.
        $member = ['condominium_id' => $this->alpha, 'role' => 'sindico', 'name' => 'Ana Moradora', 'term_years' => '2', 'term_starts_on' => '2026-03-15',
            'term_ends_on' => '2028-03-14', 'email' => 'ana@example.invalid', 'phone' => '11999990000'];
        $sindico = $this->registry->save($user, 'board', $member + ['resident_id' => $ana])['id'];
        self::assertSame([$ana, null, 2], array_map(static fn ($v) => $v === null ? null : (int)$v, array_values($this->pdo->query("SELECT resident_id, professional_manager_id, term_years FROM erp_board_members WHERE id = {$sindico}")->fetch(\PDO::FETCH_ASSOC))));
        $this->expectErrors(['resident_id'], fn () => $this->registry->save($user, 'board', ['role' => 'fiscal'] + $member + ['resident_id' => $gone]));
        $this->expectErrors(['professional_manager_id'], fn () => $this->registry->save($user, 'board', ['role' => 'consultivo'] + $member + ['professional_manager_id' => $pro]));
        $this->expectErrors(['term_ends_on'], fn () => $this->registry->save($user, 'board', ['role' => 'fiscal', 'term_years' => '1'] + $member));
        $this->registry->save($user, 'board', ['role' => 'subsindico', 'name' => 'Gestão Síndica Ltda'] + $member + ['professional_manager_id' => $pro]);

        // Morador de outro condomínio não serve.
        $both = $this->user([$this->alpha, $this->beta], ['erp.residents.view']);
        $other = $this->registry->save($both, 'residents', ['condominium_id' => $this->beta, 'unit_label' => '1', 'relationship' => 'owner', 'name' => 'Dora Beta'])['id'];
        $this->expectErrors(['resident_id'], fn () => $this->registry->save($both, 'board', ['role' => 'fiscal'] + $member + ['resident_id' => $other]));

        // Ligado a membro ativo, não sai: inativa-se.
        $this->expectException(RegistryInUse::class);
        $this->registry->delete($user, 'professional_managers', $pro);
    }

    public function testResidentsNeedPermissionAndScope(): void
    {
        $user = $this->user([$this->alpha], ['erp.residents.view']);
        $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_label' => '1', 'relationship' => 'owner', 'name' => 'Ana']);

        $noResidents = $this->user([$this->alpha]);
        $list = (new BoardCandidates($this->pdo))->list($noResidents, $this->alpha);
        self::assertSame([false, []], [$list['residents_allowed'], $list['residents']], 'LGPD: sem erp.residents.view, sem moradores.');

        try {
            (new BoardCandidates($this->pdo))->list($user, $this->beta);
            self::fail('Condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new BoardCandidates($this->pdo))->list($user, 'abc');
    }

    public function testFormShowsOnlyTheLinkedPerson(): void
    {
        $user = $this->user([$this->alpha], ['erp.residents.view']);
        $ana = $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_label' => '1', 'relationship' => 'owner', 'name' => 'Ana'])['id'];
        $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_label' => '2', 'relationship' => 'owner', 'name' => 'Beto']);
        $refs = $this->registry->references($user, RegistryService::definition('board'), ['resident_id' => $ana]);
        self::assertSame([['id' => $ana, 'condominium_id' => $this->alpha, 'label' => 'Ana']], $refs['resident_id']);
        self::assertSame([], $refs['professional_manager_id']);
        self::assertSame([], $this->registry->references($user, RegistryService::definition('board'))['resident_id'], 'Sem vínculo, sem lista.');
    }

    public function testOldMemberStillEditableAfterTheRules(): void
    {
        $user = $this->user([$this->alpha], ['erp.residents.view']);
        $ana = $this->registry->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_label' => '1', 'relationship' => 'owner', 'name' => 'Ana'])['id'];
        $member = $this->registry->save($user, 'board', ['condominium_id' => $this->alpha, 'role' => 'fiscal', 'name' => 'Ana', 'resident_id' => $ana,
            'term_starts_on' => '2026-01-01', 'term_ends_on' => '2027-12-31', 'email' => 'ana@example.invalid', 'phone' => '11999990000'])['id'];
        // Mandato de 3 anos gravado antes da regra e morador que saiu do prédio.
        $this->pdo->exec("UPDATE erp_board_members SET term_ends_on = '2028-12-31', term_years = NULL WHERE id = {$member}");
        $this->pdo->exec("UPDATE erp_residents SET status = 'inactive' WHERE id = {$ana}");
        $data = $this->registry->find($user, 'board', $member);
        $this->registry->save($user, 'board', array_merge($data, ['phone' => '11988887777', 'status' => 'inactive']), $member);
        self::assertSame('11988887777', $this->pdo->query("SELECT phone FROM erp_board_members WHERE id = {$member}")->fetchColumn(), 'Só o telefone mudou: grava.');
        $this->expectErrors(['term_ends_on'], fn () => $this->registry->save($user, 'board', array_merge($data, ['term_ends_on' => '2029-01-31']), $member));
    }

    /** @param list<string> $fields */
    private function expectErrors(array $fields, callable $action): void
    {
        try {
            $action();
            self::fail('Esperava erro de validação.');
        } catch (RegistryValidationException $exception) {
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $exception->errors, implode(', ', array_keys($exception->errors)));
            }
        }
    }

    /** @param list<string> $permissions */
    private function user(array $condominiums, array $permissions = []): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($permissions !== []) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            foreach ($permissions as $permission) {
                $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$permission, $permission]);
                $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$role, $permission]);
            }
        }
        Access::clear();
        return (new User())->findById($id);
    }
}
