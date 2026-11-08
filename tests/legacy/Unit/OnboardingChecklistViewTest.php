<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Core\View;

/** Tela e rotas do checklist de chegada do condomínio (ADR 0013). */
final class OnboardingChecklistViewTest extends TestCase
{
    public function testRoutesRegisterChecklistUnderErpGroup(): void
    {
        $route = new class {
            public array $calls = [];
            public function __call(string $name, array $args): void { $this->calls[] = [$name, ...$args]; }
        };
        require moves_container_path('erp', 'connect') . '/routes.php';

        self::assertContains(['get', '/condominios/{id}/checklist', 'CondominiumChecklist:show'], $route->calls);
        self::assertContains(['post', '/condominios/{id}/checklist/iniciar', 'CondominiumChecklist:start'], $route->calls);
        self::assertContains(['post', '/condominios/{id}/checklist/itens', 'CondominiumChecklist:add'], $route->calls);
        self::assertContains(['post', '/condominios/{id}/checklist/itens/{item}', 'CondominiumChecklist:update'], $route->calls);
        self::assertTrue(class_exists(\Source\Controllers\Erp\Connect\CondominiumChecklist::class));
        self::assertTrue(class_exists(\Source\Controllers\Api\V1\CondominiumOnboarding::class));
        self::assertContains(['get', '/checklist/catalogo', 'OnboardingCatalog:index'], $route->calls);
        self::assertContains(['post', '/checklist/catalogo', 'OnboardingCatalog:create'], $route->calls);
        self::assertContains(['post', '/checklist/catalogo/{id}', 'OnboardingCatalog:update'], $route->calls);
        self::assertTrue(class_exists(\Source\Controllers\Api\V1\OnboardingTemplates::class));
    }

    public function testCatalogPageListsAndEditsWithEscaping(): void
    {
        $user = new class {
            public string $first_name = 'Ana';
            public string $last_name = 'Teste';
            public function can(string $permission): bool { return true; }
        };
        $template = ['id' => 5, 'code' => 'avcb', 'category' => 'seguranca', 'title' => 'AVCB <i>x</i>', 'description' => null,
            'default_due_days' => 90, 'renewal_months' => null, 'position' => 10, 'active' => true, 'usage_count' => 3];
        $view = new View(moves_container_path('erp', 'connect') . '/');
        $html = $view->render('pages/checklist/catalog', ['head' => '', 'user' => $user, 'active' => 'condominiums',
            'templates' => [$template], 'editing' => $template, 'creating' => false]);

        self::assertStringContainsString('AVCB &lt;i&gt;x&lt;/i&gt;', $html);
        self::assertStringContainsString('Não vence', $html);
        self::assertStringContainsString('/erp/checklist/catalogo/5', $html);
        self::assertStringContainsString('Usado em 3 condomínio(s)', $html);
        self::assertStringNotContainsString('name="code"', $html, 'Código não muda depois de criado.');

        $new = $view->render('pages/checklist/catalog', ['head' => '', 'user' => $user, 'active' => 'condominiums',
            'templates' => [], 'editing' => null, 'creating' => true]);
        self::assertStringContainsString('name="code"', $new);
    }

    public function testAlertMessageAndMailTemplate(): void
    {
        $item = ['title' => 'Extintores', 'situation' => 'expired', 'expires_at' => '2026-11-01', 'due_date' => null, 'protocol' => null];
        self::assertSame('Extintores do Aurora venceu em 01/11/2026.', \Source\Services\OnboardingAlerts::message('Aurora', $item));
        $overdue = ['title' => 'AVCB', 'situation' => 'overdue', 'expires_at' => null, 'due_date' => '2026-11-10', 'protocol' => 'CHK-2026-000004'];
        self::assertSame('O prazo do protocolo CHK-2026-000004 (AVCB, Aurora) venceu em 10/11/2026.', \Source\Services\OnboardingAlerts::message('Aurora', $overdue));

        $html = (new \Source\Support\MailTemplate())->render('checklist-alert', ['first_name' => '<b>Ana</b>', 'message' => 'Vence', 'protocol' => 'CHK-2026-000004', 'link' => 'https://erp.connect.lab/x']);
        self::assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt;', $html);
        self::assertStringContainsString('CHK-2026-000004', $html);
    }

    public function testEmptyChecklistOffersStartOnlyToManagers(): void
    {
        $manager = $this->render([], true);
        self::assertStringContainsString('Checklist ainda não iniciado', $manager);
        self::assertStringContainsString('/erp/condominios/4/checklist/iniciar', $manager);
        self::assertStringContainsString("name='csrf'", $manager);

        $viewer = $this->render([], false);
        self::assertStringNotContainsString('/checklist/iniciar', $viewer);
    }

    public function testItemsAreGroupedEscapedAndShowProtocolAndSituation(): void
    {
        $items = [
            $this->item(['id' => 11, 'title' => 'CNPJ <b>x</b>', 'status' => 'action_required', 'situation' => 'overdue', 'protocol' => 'CHK-2026-000011', 'due_date' => '2026-11-01']),
            $this->item(['id' => 12, 'category' => 'seguranca', 'title' => 'Extintores', 'status' => 'regular', 'situation' => 'expiring', 'expires_at' => '2026-11-30', 'renewal_months' => 12]),
        ];
        $html = $this->render($items, true, 11);

        self::assertStringContainsString('CNPJ &lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('CNPJ <b>x</b>', $html);
        self::assertStringContainsString('CHK-2026-000011', $html);
        self::assertStringContainsString('Prazo vencido', $html);
        self::assertStringContainsString('Vence em breve', $html);
        self::assertStringContainsString('Segurança e Bombeiros', $html);
        self::assertStringContainsString('renova a cada 12 meses', $html);
        self::assertStringContainsString('/checklist/itens/11', $html, 'Formulário de edição do item selecionado.');
        self::assertStringNotContainsString('/checklist/itens/12"', $html);

        $viewer = $this->render($items, false, 11);
        self::assertStringNotContainsString('/checklist/itens', $viewer, 'Quem só visualiza não vê formulários.');
    }

    private function item(array $overrides): array
    {
        return array_merge([
            'id' => 1, 'code' => null, 'custom' => false, 'category' => 'documentacao', 'title' => 'Item', 'description' => null,
            'status' => 'pending', 'situation' => 'pending', 'protocol' => null, 'due_date' => null, 'expires_at' => null,
            'renewal_months' => null, 'notes' => null, 'responsible' => null, 'resolved_at' => null, 'updated_at' => '2026-11-08 10:00:00',
        ], $overrides);
    }

    private function render(array $items, bool $canManage, int $editing = 0): string
    {
        $user = new class {
            public string $first_name = 'Ana';
            public string $last_name = 'Teste';
            public function can(string $permission): bool { return true; }
        };
        $total = count($items);
        $view = new View(moves_container_path('erp', 'connect') . '/');
        return $view->render('pages/condominiums/checklist', [
            'head' => '', 'user' => $user, 'active' => 'condominiums',
            'condominium' => ['id' => 4, 'name' => 'Residencial Aurora', 'document' => null, 'status' => 'active'],
            'items' => $items,
            'summary' => ['total' => $total, 'done' => 0, 'open' => 1, 'pending' => 0, 'attention' => 2, 'progress' => 0, 'by_situation' => []],
            'canManage' => $canManage, 'responsibles' => [['id' => 9, 'name' => 'Ana Teste']], 'editing' => $editing,
        ]);
    }
}
