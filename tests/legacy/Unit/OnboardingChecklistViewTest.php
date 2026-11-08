<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Core\View;
use Source\Models\User;

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
        $user = new User();
        $user->id = 1;
        $user->first_name = 'Ana';
        $user->last_name = 'Teste';
        $total = count($items);
        $view = new View(moves_container_path('erp', 'connect') . '/');
        return $view->render('pages/condominiums/checklist', (new Panel())->layoutData($user, 'AP-004', [
            'condominium' => ['id' => 4, 'name' => 'Residencial Aurora', 'document' => null, 'status' => 'active'],
            'items' => $items,
            'summary' => ['total' => $total, 'done' => 0, 'open' => 1, 'pending' => 0, 'attention' => 2, 'progress' => 0, 'by_situation' => []],
            'canManage' => $canManage, 'responsibles' => [['id' => 9, 'name' => 'Ana Teste']], 'editing' => $editing,
        ], 'Teste'));
    }
}
