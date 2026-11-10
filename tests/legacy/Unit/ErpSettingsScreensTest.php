<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\SettingsScreens as Screens;
use Source\Services\Erp\ErpSettings;

/**
 * Telas de configurações (ADR 0144): tela própria só com dados, cada página de parâmetros tem chaves no
 * catálogo do serviço, e o campo do formulário segue o tipo e os limites da chave.
 */
final class ErpSettingsScreensTest extends TestCase
{
    public function testPagesTemplatesAndPermissions(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (Screens::PAGES as $page => $kind) {
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame(Screens::template($page), Panel::ownTemplate($page, ['settingsReady' => true]));
            self::assertSame('erp.settings.view', $permissions[$page]);
        }
        $catalogPages = array_unique(array_column(ErpSettings::CATALOG, 'page'));
        foreach (array_keys(array_filter(Screens::PAGES, static fn (string $k): bool => $k === 'params')) as $page) {
            self::assertContains($page, $catalogPages, "{$page}: tem parâmetros no catálogo");
        }
        foreach ($catalogPages as $page) {
            self::assertSame('params', Screens::PAGES[$page] ?? null, "{$page}: os parâmetros do catálogo têm tela");
        }
    }

    public function testFieldFollowsTheCatalogType(): void
    {
        $setting = static fn (string $key, mixed $value): array => ['key' => $key, 'value' => $value, 'options' => ErpSettings::CATALOG[$key]['options'] ?? null,
            'min' => ErpSettings::CATALOG[$key]['min'] ?? null, 'max' => ErpSettings::CATALOG[$key]['max'] ?? null, 'type' => ErpSettings::CATALOG[$key]['type']];
        self::assertSame(['tag' => 'input', 'type' => 'number', 'attrs' => ['min' => '1', 'max' => '28', 'step' => '1'], 'value' => '10'], Screens::field($setting('finance.default_due_day', 10)));
        self::assertEquals(['minlength' => '2', 'maxlength' => '80'], Screens::field($setting('app.display_name', 'Connect'))['attrs']);
        self::assertSame('1500,00', Screens::field($setting('alerts.low_balance_cents', 150000))['value']);
        self::assertSame('0', Screens::field($setting('app.notices_enabled', false))['value']);
        self::assertSame('select', Screens::field($setting('statement.group_by', 'chart'))['tag']);
        self::assertSame('Centro de custo', Screens::display($setting('statement.group_by', 'cost_center')));
        self::assertSame('R$ 1.500,00', Screens::display($setting('alerts.low_balance_cents', 150000)));
        foreach (array_keys(ErpSettings::CATALOG) as $key) {
            $field = Screens::field($setting($key, ErpSettings::CATALOG[$key]['default']));
            if ($field['type'] !== 'bool') {
                self::assertSame(ErpSettings::normalize($key, $field['type'] === 'enum' ? $field['value'] : ($field['value'] === '' ? ErpSettings::CATALOG[$key]['default'] : $field['value'])),
                    ErpSettings::normalize($key, ErpSettings::CATALOG[$key]['default']), "{$key}: o valor do campo volta igual pela API");
            }
        }
    }

    public function testYear(): void
    {
        self::assertSame(2026, Screens::year(null, 2026));
        self::assertSame(2030, Screens::year('2030', 2026));
        self::assertSame(2026, Screens::year('1999', 2026));
    }
}
