<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitMoveTerms;

/** Termo de entrada e de saída (ADR 0311): texto do condomínio, geral e padrão; troca segura das palavras-chave. */
final class UnitMoveTermsTest extends TestCase
{
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha <Tower>');
        $this->beta = $this->condominium('Beta');
    }

    public function testFallsBackFromCondominiumToGeneralToDefault(): void
    {
        $admin = $this->user([$this->alpha, $this->beta], true);
        $terms = new UnitMoveTerms($this->pdo);
        self::assertSame('default', $terms->forEdit($admin, $this->alpha, 'entrada')['source']);

        $terms->save($admin, null, 'entrada', "Geral para @condominio.\r\n");
        self::assertSame(['general', 'Geral para @condominio.'], [$terms->forEdit($admin, $this->alpha, 'entrada')['source'], $terms->forEdit($admin, $this->alpha, 'entrada')['body']]);
        self::assertSame('default', $terms->forEdit($admin, $this->alpha, 'saida')['source'], 'Cada tipo tem o seu texto.');

        $terms->save($admin, $this->alpha, 'entrada', 'Próprio da Alpha.');
        $terms->save($admin, $this->alpha, 'entrada', 'Próprio da Alpha, revisto.');
        self::assertSame('condominium', $terms->forEdit($admin, $this->alpha, 'entrada')['source']);
        self::assertSame('general', $terms->forEdit($admin, $this->beta, 'entrada')['source']);
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_unit_move_terms'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_unit_move_terms')->fetchColumn(), 'Um texto por condomínio e tipo.');

        $this->assertInvalid('body', fn () => $terms->save($admin, $this->alpha, 'entrada', '   '));
        $this->assertInvalid('kind', fn () => $terms->save($admin, $this->alpha, 'mudanca', 'x'));
    }

    public function testScopeForGeneralTextAndRendering(): void
    {
        $user = $this->user([$this->alpha]);
        $terms = new UnitMoveTerms($this->pdo);
        $this->expectNotFound(fn () => $terms->save($user, null, 'entrada', 'Geral'), 'Texto geral exige acesso a todos.');
        $this->expectNotFound(fn () => $terms->forEdit($user, $this->beta, 'entrada'), 'Condomínio fora do escopo.');
        $this->pdo->exec("UPDATE condominiums SET city = 'Niterói', state = 'RJ' WHERE id = {$this->alpha}");

        $service = new RegistryService($this->pdo);
        $unit = (int)$service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'A', 'number' => '101', 'type' => 'apartamento'])['id'];
        $person = (int)$service->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'tenant', 'name' => 'Ana <b>Lima</b>', 'document' => '52998224725'])['id'];
        $terms->save($user, $this->alpha, 'saida', "@morador (@documento) sai da @unidade, bloco @bloco, @condominio.\n@cidade/@uf, @dataCriado. @desconhecido\n\n@linhaAssinatura");

        $term = $terms->render($user, $unit, $person, 'saida', new \DateTimeImmutable('2026-03-05'));
        self::assertSame('Termo de saída', $term['title']);
        self::assertStringContainsString('Ana &lt;b&gt;Lima&lt;/b&gt; (529.982.247-25) sai da A - 101, bloco A, Alpha &lt;Tower&gt;.<br>Niterói/RJ, 5 de março de 2026. @desconhecido</p>', $term['html']);
        self::assertStringContainsString('<p><span class="erp-move-term__sign"', $term['html']);
        self::assertStringNotContainsString('<b>', $term['html'], 'Nada do texto ou dos dados vira HTML.');

        $blank = $terms->render($user, $unit, null, 'entrada');
        self::assertSame('default', $blank['source']);
        self::assertNull($blank['person']);

        $other = (int)$service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento'])['id'];
        $this->assertInvalid('resident_id', fn () => $terms->render($user, $other, $person, 'saida'));
        $this->expectNotFound(fn () => $terms->render($this->user([$this->beta]), $unit, $person, 'saida'), 'Unidade fora do escopo.');
    }

    private function expectNotFound(callable $call, string $message): void
    {
        try {
            $call();
            self::fail($message);
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
    }

    private function assertInvalid(string $field, callable $call): void
    {
        try {
            $call();
            self::fail('Aceitou dado inválido em ' . $field . '.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors);
        }
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
