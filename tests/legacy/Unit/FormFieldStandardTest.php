<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Campo padrão e espaçamento em todo o sistema (ADR 0628, pedido do Djalma em 20/11/2026): o texto de
 * ajuda embaixo de um campo não pode desalinhar o campo vizinho, os blocos não ficam colados e a
 * barra de Salvar não anda junto com a rolagem.
 */
final class FormFieldStandardTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private function minified(string $css): string
    {
        return (string)preg_replace('/\s+/', '', $css);
    }

    public function testStudioAndHelpDeskFieldsAlignByTheTop(): void
    {
        $css = $this->file('container/cms/studio/v1/assets/css/studio-system.css');
        self::assertStringContainsString(".studio-v2 .app_form label { align-content: start }", $css);
        self::assertStringContainsString(".studio-v2 .studio-form-grid { align-items: start }", $css);
        $bundle = $this->minified($this->file('container/cms/studio/v1/assets/studio.min.css'));
        self::assertStringContainsString('.studio-v2.app_formlabel{align-content:start}', $bundle, 'studio.min.css regenerado (composer build:studio)');
    }

    public function testStudioLabelsOfTheSameRowShareHeight(): void
    {
        self::assertStringContainsString('studio-field-label', $this->file('container/cms/studio/v1/assets/js/scripts.js'));
        self::assertStringContainsString('studio-field-label', $this->file('container/cms/studio/v1/assets/studio.min.js'), 'studio.min.js regenerado');
    }

    public function testSaveBarsStayAtTheEndOfTheForm(): void
    {
        $css = $this->file('container/cms/studio/v1/assets/css/studio-system.css');
        self::assertMatchesRegularExpression('/\.studio-v2 \.studio-settings-save,\s*\.studio-v2 \.studio-access-save \{ position: static;/', $css);
        self::assertStringContainsString('.studio-v2 .studio-content { padding-bottom: 104px }', $css, 'espaço para o botão Ajuda');
        $bundle = $this->minified($this->file('container/cms/studio/v1/assets/studio.min.css'));
        self::assertStringContainsString('.studio-v2.studio-settings-save,.studio-v2.studio-access-save{position:static', $bundle);
    }

    public function testErpFieldsWithoutWidthAndBlocksHaveRoom(): void
    {
        $css = $this->file('container/erp/connect/v1/assets/css/erp.css');
        self::assertStringContainsString('.erp-form__grid > :where(:not([class*="erp-col-"])) { grid-column: span 6; }', $css);
        self::assertStringContainsString(':where(.erp-filters, .erp-alert, .erp-table-wrap) + :where(.erp-card, .erp-table-wrap, .erp-form__actions, .erp-alert, .erp-filters) { margin-top: var(--erp-space-4); }', $css);
        self::assertStringContainsString('.erp-navbar .erp-breadcrumbs { display: none; }', $css);
    }

    public function testResidentAppAndHelpCenterRowsAlignByTheTop(): void
    {
        $app = $this->file('container/app/connect/v1/assets/css/app.css');
        self::assertStringContainsString('.app-field { display: grid; gap: 6px; align-content: start;', $app);
        self::assertStringContainsString('.app-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: start; }', $app);
        self::assertStringContainsString('.app-field{display:grid;gap:6px;align-content:start', $this->file('container/app/connect/v1/assets/style.css'), 'style.css do app regenerado');
        self::assertStringContainsString('.hc-form__row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }', $this->file('container/web/support/v1/assets/support.css'));
    }
}
