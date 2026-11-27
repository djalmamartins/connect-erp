<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Core\View;

/** ADR 0686: síndico e conselho entre moradores e síndicos profissionais, mandato de 1 ou 2 anos; sem banco. */
final class BoardMemberPickerTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function member(array $extra = []): array
    {
        return $extra + ['condominium_id' => '1', 'role' => 'sindico', 'name' => 'Marta', 'term_starts_on' => '2026-03-15',
            'term_ends_on' => '2028-03-14', 'email' => 'marta@example.invalid', 'phone' => '11999990000'];
    }

    public function testTermYearsLimitsTheEnd(): void
    {
        $board = Definitions::get('board');
        self::assertSame(['1' => '1 ano', '2' => '2 anos'], $board['fields']['term_years']['options']);

        [$clean, $errors] = RegistryValidator::validate($board, $this->member());
        self::assertSame([], $errors, 'Sem a duração (API antiga), vale o padrão de 2 anos.');
        self::assertSame('2', $clean['term_years']);

        [, $errors] = RegistryValidator::validate($board, $this->member(['term_years' => '2', 'term_ends_on' => '2028-03-15']));
        self::assertSame([], $errors, 'Até início + 2 anos é aceito.');
        [, $errors] = RegistryValidator::validate($board, $this->member(['term_years' => '2', 'term_ends_on' => '2028-03-16']));
        self::assertStringContainsString('15/03/2028', $errors['term_ends_on'] ?? '');

        [, $errors] = RegistryValidator::validate($board, $this->member(['term_years' => '1', 'term_ends_on' => '2027-03-14']));
        self::assertSame([], $errors);
        [, $errors] = RegistryValidator::validate($board, $this->member(['term_years' => '1']));
        self::assertStringContainsString('1 ano', $errors['term_ends_on'] ?? '', 'Mandato de 1 ano não vai até 2028.');
        [, $errors] = RegistryValidator::validate($board, $this->member(['term_years' => '3']));
        self::assertArrayHasKey('term_years', $errors, 'O máximo é 2 anos.');
    }

    public function testAddYearsHandlesLeapDay(): void
    {
        self::assertSame('2028-03-15', RegistryValidator::addYears('2026-03-15', 2));
        self::assertSame('2025-02-28', RegistryValidator::addYears('2024-02-29', 1));
        self::assertNull(RegistryValidator::addYears('15/03/2026', 1));
    }

    public function testResidentOrProfessionalNeverBoth(): void
    {
        $board = Definitions::get('board');
        [$clean, $errors] = RegistryValidator::validate($board, $this->member(['resident_id' => '7']));
        self::assertSame([], $errors);
        self::assertSame(7, $clean['resident_id']);
        [, $errors] = RegistryValidator::validate($board, $this->member(['professional_manager_id' => '3']));
        self::assertSame([], $errors, 'Síndico profissional como síndico.');
        [, $errors] = RegistryValidator::validate($board, $this->member(['role' => 'subsindico', 'professional_manager_id' => '3']));
        self::assertSame([], $errors, 'E como subsíndico.');

        [, $errors] = RegistryValidator::validate($board, $this->member(['resident_id' => '7', 'professional_manager_id' => '3']));
        self::assertArrayHasKey('resident_id', $errors);
        foreach (['fiscal', 'consultivo', 'suplente'] as $role) {
            [, $errors] = RegistryValidator::validate($board, $this->member(['role' => $role, 'professional_manager_id' => '3']));
            self::assertStringContainsString('conselho é formado por moradores', $errors['professional_manager_id'] ?? '', $role);
        }
    }

    public function testProfessionalManagersRegistry(): void
    {
        $def = Definitions::get('professional_managers');
        self::assertSame(['CN-013', 'erp_professional_managers', '/cadastros/sindicos-profissionais', 'professional-managers', 'erp.board', 'none'],
            [$def['page'], $def['table'], $def['route'], $def['api'], $def['permission'], $def['scope']]);
        [, $errors] = RegistryValidator::validate($def, []);
        self::assertSame(['name', 'email', 'phone'], array_keys($errors));
        $catalog = require self::THEME . 'components/layout/catalog.php';
        self::assertSame('/cadastros/sindicos-profissionais', $catalog['pages']['CN-013']['route']);
        $board = Definitions::get('board')['fields'];
        self::assertSame('professional_managers', $board['professional_manager_id']['ref']);
        self::assertTrue($board['resident_id']['ref_lazy'] && $board['professional_manager_id']['ref_lazy'], 'Sem lista de centenas de moradores no formulário.');
    }

    public function testLazyRefRendersLinkedNameAndOnlyIfList(): void
    {
        $view = new View(moves_container_path('erp', 'connect') . '/');
        $fields = Definitions::get('board')['fields'];
        $render = static fn (string $name, mixed $value, array $options): string => $view->render('components/forms/registry-field', [
            'field' => $fields[$name], 'value' => $value, 'error' => null, 'readonly' => false, 'condominiums' => [], 'units' => [],
            'refOptions' => $options, 'attachment' => null,
        ]);
        $html = $render('resident_id', 7, [['id' => 7, 'condominium_id' => 1, 'label' => 'Ana <b>']]);
        self::assertStringContainsString('type="hidden" id="f-resident_id" name="resident_id" value="7"', $html);
        self::assertStringContainsString('Ana &lt;b&gt;', $html);
        self::assertStringNotContainsString('<select', $html);
        $html = $render('professional_manager_id', null, []);
        self::assertStringContainsString('data-only-if="f-role=sindico|subsindico"', $html);
        self::assertStringContainsString('>Nenhum<', $html);
    }

    public function testPickerAssetsAndApi(): void
    {
        $js = (string)file_get_contents(self::THEME . 'assets/js/erp.js');
        foreach (['[data-erp-board-picker]', "COUNCIL = ['fiscal', 'consultivo', 'suplente']", 'aria-activedescendant', 'end.setUTCDate(end.getUTCDate() - 1)', "split('|')"] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
        self::assertStringContainsString('data-erp-board-picker', (string)file_get_contents(self::THEME . 'assets/erp.min.js'), 'erp.min.js regenerado');
        self::assertStringContainsString('.erp-board-suggest', (string)file_get_contents(self::THEME . 'assets/erp.min.css'), 'erp.min.css regenerado');
        $index = (string)file_get_contents('index.php');
        self::assertLessThan(strpos($index, 'Definitions::all() as $registrySlug'), strpos($index, '"/board-members/candidates"'), 'Antes de /board-members/{id}.');
        $controller = (string)file_get_contents('source/Controllers/Api/V1/BoardCandidates.php');
        self::assertStringContainsString("Access::can('erp.board.manage', \$user)", $controller);
        $service = (string)file_get_contents('source/Services/Erp/BoardCandidates.php');
        self::assertStringContainsString("Access::can('erp.residents.view', \$user)", $service);
        self::assertStringContainsString('$this->scope->assertAccess($user, $id)', $service);
    }
}
