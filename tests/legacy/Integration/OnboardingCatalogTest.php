<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\OnboardingCatalog as CatalogPage;
use Source\Services\OnboardingCatalog;
use Source\Services\OnboardingChecklist;
use Source\Services\OnboardingValidationError;

/** Catálogo do checklist de chegada (ADR 0013). */
final class OnboardingCatalogTest extends TestCase
{
    /** O catálogo semeado não é truncado entre testes; os itens criados aqui são removidos. */
    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM onboarding_item_templates WHERE code LIKE 'teste_%'");
        $this->pdo->exec("UPDATE onboarding_item_templates SET active = 1, default_due_days = 30, renewal_months = 12, title = 'Extintores: recarga e inspeção' WHERE code = 'extintores'");
        parent::tearDown();
    }

    public function testCreateValidatesAndNewItemReachesExistingChecklistOnSync(): void
    {
        $this->pdo->exec("INSERT INTO condominiums (name) VALUES ('Aurora')");
        $condo = (int)$this->pdo->lastInsertId();
        $checklist = new OnboardingChecklist($this->pdo);
        $before = $checklist->start($condo);
        $catalog = new OnboardingCatalog($this->pdo);

        $created = $catalog->create(['code' => 'teste_gas', 'category' => 'seguranca', 'title' => 'Laudo da rede de gás', 'default_due_days' => '45', 'renewal_months' => '60', 'position' => '60', 'active' => true]);
        self::assertSame(45, $created['default_due_days']);
        self::assertSame(60, $created['renewal_months']);
        self::assertSame(1, $checklist->start($condo), '"Atualizar com o catálogo" traz só o item novo.');
        self::assertCount($before + 1, $checklist->items($condo));

        try {
            $catalog->create(['code' => 'teste_gas', 'category' => 'x', 'title' => '', 'default_due_days' => 0, 'renewal_months' => 'abc']);
            self::fail('Entrada inválida aceita.');
        } catch (OnboardingValidationError $error) {
            self::assertEqualsCanonicalizing(['code', 'category', 'title', 'default_due_days', 'renewal_months'], array_keys($error->errors));
        }
    }

    public function testUpdateKeepsCodeAndInactiveItemIsNotCopied(): void
    {
        $catalog = new OnboardingCatalog($this->pdo);
        $id = (int)$this->pdo->query("SELECT id FROM onboarding_item_templates WHERE code = 'extintores'")->fetchColumn();

        $updated = $catalog->update($id, ['default_due_days' => 15, 'renewal_months' => '', 'active' => false, 'code' => 'outro']);
        self::assertSame('extintores', $updated['code']);
        self::assertSame(15, $updated['default_due_days']);
        self::assertNull($updated['renewal_months']);
        self::assertFalse($updated['active']);

        $this->pdo->exec("INSERT INTO condominiums (name) VALUES ('Beta')");
        $condo = (int)$this->pdo->lastInsertId();
        (new OnboardingChecklist($this->pdo))->start($condo);
        self::assertNotContains('extintores', array_column((new OnboardingChecklist($this->pdo))->items($condo), 'code'));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'onboarding_item_templates'")->fetchColumn());
    }

    public function testPageRequiresCatalogPermission(): void
    {
        $userId = $this->createUser();
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Operador', 'operador', 20)");
        foreach (['erp.access', 'erp.onboarding.catalog'] as $slug) {
            $this->pdo->prepare("INSERT INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$slug, $slug]);
        }
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p WHERE p.slug = 'erp.access'");
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'operador'")->execute([$userId]);
        $_SESSION['authUser'] = $userId;

        self::assertStringContainsString('Acesso negado', $this->page());

        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p WHERE p.slug = 'erp.onboarding.catalog'");
        $html = $this->page();
        self::assertStringContainsString('Catálogo do checklist de chegada', $html);
        self::assertStringContainsString('AVCB / CLCB do Corpo de Bombeiros', $html);
    }

    private function page(): string
    {
        \Source\Support\Access::clear();
        ob_start();
        (new CatalogPage())->index();
        return (string)ob_get_clean();
    }
}
