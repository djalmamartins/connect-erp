<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\CondominiumChecklist;
use Source\Services\CondominiumScope;
use Source\Services\OnboardingChecklist;

/** Tela do checklist: escopo por condomínio e permissão de gestão no servidor (ADR 0013). */
final class CondominiumChecklistControllerTest extends TestCase
{
    public function testLinkedCondominiumRendersChecklistAndOtherIsNotFound(): void
    {
        $linked = $this->condominium('Residencial Aurora');
        $other = $this->condominium('Edifício Outro');
        (new OnboardingChecklist($this->pdo))->start($linked);
        $userId = $this->userWith(['erp.access', 'erp.condominiums.view']);
        (new CondominiumScope($this->pdo))->grant($userId, $linked);
        (new CondominiumScope($this->pdo))->grant($userId, $other);
        $this->pdo->prepare("UPDATE condominiums SET status = 'inactive' WHERE id = ?")->execute([$other]);
        $_SESSION['authUser'] = $userId;

        $html = $this->show($linked);
        self::assertSame(200, http_response_code() ?: 200);
        self::assertStringContainsString('Checklist de chegada', $html);
        self::assertStringContainsString('CNPJ do condomínio', $html);
        self::assertStringContainsString('AVCB', $html);
        self::assertStringNotContainsString('/checklist/iniciar', $html, 'Sem erp.condominiums.manage não há ações.');

        $denied = $this->show($other);
        self::assertStringContainsString('Condomínio não encontrado', $denied);
        self::assertStringNotContainsString('Edifício Outro', $denied);

        $unlinked = $this->condominium('Sem vínculo');
        self::assertStringContainsString('Condomínio não encontrado', $this->show($unlinked));
    }

    public function testManagerSeesStartAndEditForms(): void
    {
        $condo = $this->condominium('Residencial Aurora');
        $userId = $this->userWith(['erp.access', 'erp.condominiums.view', 'erp.condominiums.manage']);
        (new CondominiumScope($this->pdo))->grant($userId, $condo);
        $_SESSION['authUser'] = $userId;

        self::assertStringContainsString("/erp/condominios/{$condo}/checklist/iniciar", $this->show($condo));

        (new OnboardingChecklist($this->pdo))->start($condo);
        $item = (new OnboardingChecklist($this->pdo))->items($condo)[0];
        $_GET['item'] = (string)$item['id'];
        $html = $this->show($condo);
        unset($_GET['item']);
        self::assertStringContainsString("/checklist/itens/{$item['id']}", $html);
        self::assertStringContainsString('Registrar outra pendência', $html);
    }

    public function testWithoutViewPermissionIsForbidden(): void
    {
        $condo = $this->condominium('Residencial Aurora');
        $userId = $this->userWith(['erp.access']);
        (new CondominiumScope($this->pdo))->grant($userId, $condo);
        $_SESSION['authUser'] = $userId;

        self::assertStringContainsString('Acesso negado', $this->show($condo));
    }

    private function show(int $condominiumId): string
    {
        \Source\Support\Access::clear();
        ob_start();
        (new CondominiumChecklist())->show(['id' => (string)$condominiumId]);
        return (string)ob_get_clean();
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): int
    {
        $userId = $this->createUser(['level' => 2]);
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level) VALUES ('Operador', 'operador', 20)");
        $roleId = (int)$this->pdo->query("SELECT id FROM access_roles WHERE slug = 'operador'")->fetchColumn();
        foreach (['erp.access', 'erp.condominiums.view', 'erp.condominiums.manage'] as $slug) {
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$slug, $slug]);
        }
        foreach ($permissions as $slug) {
            $this->pdo->prepare('INSERT IGNORE INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$roleId, $slug]);
        }
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        return $userId;
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }
}
