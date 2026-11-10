<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use Source\Core\View;
use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Models\User;
use Source\Services\Erp\CondominiumSetup;
use Source\Services\Erp\Registry\Definitions;
use Source\Support\Erp\CondominiumContext;

/**
 * ADR 0151: menu Condomínios abre a visão geral do condomínio da barra superior, lista com
 * "Selecionar" em Todos, guia de cadastro na ordem pedida e e-mail/telefone do conselho. Sem banco.
 */
final class CondominiumSetupTest extends TestCase
{
    protected function setUp(): void
    {
        CondominiumContext::reset();
    }

    protected function tearDown(): void
    {
        CondominiumContext::reset();
    }

    public function testStepsFollowTheRequestedOrderAndEndWithTheChecklist(): void
    {
        $keys = array_column(CondominiumSetup::definitions(5), 'key');
        self::assertSame(['condominium', 'units', 'owners', 'board', 'areas', 'checklist'], $keys);
        $last = CondominiumSetup::definitions(5)[5];
        self::assertSame('/condominios/5/checklist', $last['route']);
        self::assertSame('/condominios/unidades/cadastro-rapido', CondominiumSetup::definitions(5)[1]['route']);
    }

    public function testCurrentIndexMatchesTheScreenOfEachStep(): void
    {
        $steps = CondominiumSetup::definitions(5);
        self::assertSame(1, CondominiumSetup::currentIndex($steps, '/condominios/unidades/cadastro-rapido'));
        self::assertSame(1, CondominiumSetup::currentIndex($steps, '/condominios/unidades'));
        self::assertSame(2, CondominiumSetup::currentIndex($steps, '/cadastros/proprietarios-e-inquilinos/novo'));
        self::assertSame(3, CondominiumSetup::currentIndex($steps, '/cadastros/sindico-e-conselho'));
        self::assertSame(4, CondominiumSetup::currentIndex($steps, '/condominios/areas-vinculaveis'));
        self::assertSame(5, CondominiumSetup::currentIndex($steps, '/condominios/5/checklist'));
        self::assertNull(CondominiumSetup::currentIndex($steps, '/condominios/6/checklist'), 'Checklist de outro condomínio não é passo deste guia.');
        self::assertNull(CondominiumSetup::currentIndex($steps, '/financeiro/lancamentos'));
        self::assertNull(CondominiumSetup::currentIndex($steps, '/condominios/unidades-extra'), 'Prefixo só casa no limite do segmento.');
    }

    public function testNextPendingSkipsDoneStepsAndFallsBackToTheChecklist(): void
    {
        $steps = array_map(static fn (array $s): array => $s + ['done' => in_array($s['key'], ['condominium', 'units'], true)], CondominiumSetup::definitions(5));
        self::assertSame('owners', CondominiumSetup::nextPending($steps)['key']);
        $all = array_map(static fn (array $s): array => ['done' => true] + $s, $steps);
        self::assertSame('checklist', CondominiumSetup::nextPending($all)['key']);
        self::assertNull(CondominiumSetup::nextPending([]));
    }

    public function testRoutesRegisterGuideActionsAsPost(): void
    {
        $route = new class {
            public array $calls = [];
            public function __call(string $name, array $args): void { $this->calls[] = [$name, ...$args]; }
        };
        require moves_container_path('erp', 'connect') . '/routes.php';
        self::assertContains(['post', '/condominios/{id}/guia', 'Condominiums:guide'], $route->calls);
        self::assertContains(['post', '/condominios/{id}/guia/encerrar', 'Condominiums:endGuide'], $route->calls);
    }

    public function testListOffersSelectButtonThatSwitchesTheTopBar(): void
    {
        CondominiumContext::use([['id' => 7, 'name' => 'Residencial Aurora']], null);
        $html = $this->render('pages/condominiums/index', [
            'condominiums' => [['id' => 7, 'name' => 'Residencial Aurora', 'document' => null, 'status' => 'active']],
            'condoOptionsById' => array_column(CondominiumContext::options(), null, 'id'),
        ]);
        self::assertStringContainsString('Selecionar', $html);
        self::assertStringContainsString('/residencial-aurora/condominios"', $html);
        self::assertStringNotContainsString('Mostrando só', $html);
    }

    public function testOverviewShowsFullRecordAndSetupCard(): void
    {
        $steps = array_map(static fn (array $s): array => $s + ['count' => 0, 'total' => null, 'done' => $s['key'] === 'condominium', 'meta' => 'x'], CondominiumSetup::definitions(3));
        $html = $this->render('pages/condominiums/show', [
            'condominium' => ['id' => 3, 'name' => 'Ed. Aurora', 'legal_name' => 'Condomínio Edifício Aurora', 'document' => '12345678000195', 'status' => 'active',
                'zip_code' => '01001000', 'street' => 'Praça da Sé', 'number' => '10', 'complement' => '', 'district' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP',
                'phone' => '1133334444', 'email' => 'aurora@example.invalid', 'blocks_count' => 2, 'units_count' => 40, 'contract_start_date' => '2026-01-05',
                'fee_due_day' => 10, 'bank_code' => '001', 'bank_branch' => '1234', 'bank_account' => '5678-9'],
            'setupSteps' => ['condominium' => ['id' => 3, 'name' => 'Ed. Aurora', 'slug' => 'ed-aurora'], 'steps' => $steps, 'current' => null],
        ]);
        foreach (['Condomínio Edifício Aurora', '01001-000', 'Praça da Sé, 10', 'Dia 10', '05/01/2026', 'Cadastro do condomínio', 'Continuar o cadastro', '/condominios/3/guia', 'Todos os condomínios'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringContainsString("name='csrf'", $html, 'Retomar o guia é POST com CSRF.');
    }

    public function testGuideBarMarksCurrentStepAndOffersNextOrConclude(): void
    {
        $steps = array_map(static fn (array $s): array => $s + ['count' => 0, 'total' => null, 'done' => false, 'meta' => ''], CondominiumSetup::definitions(3));
        $guide = ['condominium' => ['id' => 3, 'name' => 'Ed. Aurora', 'slug' => 'ed-aurora'], 'steps' => $steps, 'current' => 1];
        $html = $this->render('components/layout/setup-guide', ['guide' => $guide]);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('Próximo: Proprietários', $html);
        self::assertStringContainsString('/condominios/3/guia/encerrar', $html);

        $guide['current'] = 5;
        $last = $this->render('components/layout/setup-guide', ['guide' => $guide]);
        self::assertStringContainsString('Concluir cadastro', $last);
        self::assertStringNotContainsString('Próximo:', $last);
    }

    public function testFormPutsCnpjFirstWithLookup(): void
    {
        $html = $this->render('pages/condominiums/form', [
            'formTitle' => 'Novo condomínio', 'action' => url('/erp/condominios/novo'), 'cancel' => url('/erp/condominios'),
            'values' => ['status' => 'active'], 'errors' => [], 'states' => ['SP'], 'statuses' => ['active' => 'Ativo', 'inactive' => 'Inativo'],
        ]);
        $cnpj = strpos($html, 'name="document"');
        self::assertNotFalse($cnpj);
        self::assertLessThan(strpos($html, 'name="name"'), $cnpj, 'CNPJ vem antes do nome.');
        self::assertLessThan(strpos($html, 'name="legal_name"'), strpos($html, 'name="name"'));
        self::assertStringContainsString('data-erp-cnpj-lookup', $html);
        self::assertStringNotContainsString('name="document" type="text" value="" inputmode="numeric" maxlength="18" placeholder="00.000.000/0000-00" data-erp-cnpj-lookup required', $html, 'CNPJ continua opcional.');
        $js = (string)file_get_contents(moves_container_path('erp', 'connect') . '/assets/js/erp.js');
        self::assertStringContainsString('brasilapi.com.br/api/cnpj/v1/', $js);
        self::assertStringContainsString("credentials: 'omit'", $js);
    }

    public function testBoardRequiresEmailAndPhone(): void
    {
        $fields = Definitions::get('board')['fields'];
        self::assertTrue($fields['email']['required'] ?? false);
        self::assertTrue($fields['phone']['required'] ?? false);
    }

    private function render(string $template, array $data): string
    {
        $user = new class extends User {
            public function __construct() { parent::__construct(); $this->id = 1; $this->first_name = 'Ana'; $this->last_name = 'Teste'; $this->email = 'ana@example.invalid'; }
            public function can(string $permission): bool { return true; }
        };
        $frame = (new Panel())->layoutData($user, 'AP-004', $data, 'Condomínios');
        return (new View(moves_container_path('erp', 'connect') . '/'))->render($template, $frame);
    }
}
