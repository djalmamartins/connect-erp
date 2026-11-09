<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Acabamento de UX e acessibilidade das telas com dados reais (AP-003, AP-114, CN-005 e chamado da
 * ocorrência): cabeçalhos de tabela com escopo, ações com nome completo, erro anunciado e filtro
 * que não envia sozinho pelo teclado.
 */
final class ErpScreensAccessibilityTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testTablesHaveScopedHeadersAndNamedActions(): void
    {
        foreach (['pages/operational/pending-reservations.php', 'pages/notifications/index.php'] as $page) {
            $html = $this->file(self::THEME . $page);
            self::assertDoesNotMatchRegularExpression('/<th(?=[\s>])(?![^>]*scope=)/', $html, "{$page}: todo <th> com scope");
            self::assertStringContainsString("role=\"<?= \$ok ? 'status' : 'alert' ?>\"", $html, "{$page}: erro do flash é alerta");
        }
        $pending = $this->file(self::THEME . 'pages/operational/pending-reservations.php');
        foreach (['Abrir', 'Confirmar', 'Recusar'] as $verb) {
            self::assertStringContainsString('aria-label="' . $verb . ' a reserva de ', $pending);
        }
        $queue = $this->file(self::THEME . 'pages/notifications/index.php');
        self::assertStringContainsString('aria-label="Ver a trilha do aviso nº', $queue);
        self::assertStringContainsString('id="trilha" tabindex="-1"', $queue);
    }

    public function testErrorsStayOnScreenAndKeyboardDoesNotAutoSubmit(): void
    {
        self::assertStringContainsString('data-ticket-result aria-live="polite"', $this->file(self::THEME . 'components/cards/occurrence-ticket.php'));
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString("if (result) result.innerHTML = '<div class=\"erp-alert erp-alert--warning\" role=\"alert\">", $js);
        self::assertStringContainsString('if (byKey || select.value', $js);
        self::assertStringContainsString('data-erp-loading', $this->file(self::THEME . 'pages/condominiums/unit-panel.php'));
    }

    public function testVisuallyHiddenClassExists(): void
    {
        $css = $this->file(self::THEME . 'assets/css/erp.css');
        self::assertStringContainsString('.erp-sr-only', $css);
        foreach (['pages/condominiums/checklist.php', 'pages/checklist/catalog.php'] as $page) {
            self::assertStringNotContainsString('erp-visually-hidden', $this->file(self::THEME . $page), 'classe sem regra no CSS');
        }
    }
}
