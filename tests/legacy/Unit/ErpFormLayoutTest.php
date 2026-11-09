<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Padrão dos formulários do ERP (pedido do Djalma, 09/11): inputs e selects com a mesma altura, a seta
 * do select pelo menos 10 px para dentro e os campos de cada linha alinhados, mesmo com texto de ajuda
 * embaixo ou rótulo em duas linhas.
 */
final class ErpFormLayoutTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testControlsShareHeightAndTheSelectArrowSitsInside(): void
    {
        $css = $this->file(self::THEME . 'assets/css/erp.css');
        self::assertStringContainsString('.erp-input, .erp-select { height: var(--erp-control-height); }', $css);
        self::assertStringContainsString('appearance: none; -webkit-appearance: none; padding-right: 40px;', $css);
        self::assertMatchesRegularExpression('/background-position: right (1[0-9]|[2-9][0-9])px center/', $css, 'seta com pelo menos 10 px de margem');
        self::assertStringContainsString('gap: var(--erp-space-4); align-items: start; }', $css, 'campos pelo topo: a ajuda embaixo não estica os vizinhos');
        self::assertStringContainsString('.erp-field { display: grid; gap: 6px; min-width: 0; align-content: start; }', $css);
        self::assertStringNotContainsString('.erp-select[disabled] { background:', $css, 'o fundo do select desabilitado não pode apagar a seta');
        self::assertStringContainsString('url("data:image/svg+xml', $this->file(self::THEME . 'assets/erp.min.css'), 'erp.min.css regenerado');
    }

    public function testLabelsOfTheSameRowShareHeight(): void
    {
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString('var alignFieldLabels = function (grid)', $js);
        self::assertStringContainsString('new ResizeObserver(', $js);
        self::assertStringContainsString('alignFieldLabels', $this->file(self::THEME . 'assets/erp.min.js'));
    }
}
