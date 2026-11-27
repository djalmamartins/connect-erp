<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Sugestão de pessoa e e-mail ao digitar em todo o sistema (ADR 0687; pedido do Djalma em 27/11/2026). Um componente
 * só (connect-suggest.js), carregado pelo connect_fields_assets() em todos os layouts, e uma API só.
 */
final class PeopleSuggestEverywhereTest extends TestCase
{
    /** Campos de pessoa ou e-mail que precisam sugerir (arquivo => trechos que precisam estar no mesmo <input>). */
    private const FIELDS = [
        'container/erp/connect/v1/pages/tickets/index.php' => ['id="tk-rname"', 'id="tk-remail"'],
        'container/erp/connect/v1/pages/operational/deliveries.php' => ['id="pickup-by"'],
        'container/erp/connect/v1/pages/assemblies/assembly.php' => ['id="as-attendee"'],
        'container/erp/connect/v1/pages/collections/desk.php' => ['id="contact-name"'],
        'container/erp/connect/v1/pages/inspections/show.php' => ['id="insp-recipients"'],
        'container/erp/connect/v1/pages/settings/admin.php' => ['id="ad-email"'],
        'container/cms/studio/v1/components/tickets/home.php' => ['id="ht-rname"', 'id="ht-remail"', 'id="ht-assigned-name"'],
    ];

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testComponentIsLoadedWithTheApiAddress(): void
    {
        $helper = $this->file('source/Boot/Helpers.php');
        self::assertStringContainsString("\$src('connect-suggest.js')", $helper);
        self::assertStringContainsString("url('/api/v1/suggest/people')", $helper);
        $js = $this->file('container/shared/assets/connect-fields/connect-suggest.js');
        foreach (["role', 'combobox'", 'aria-activedescendant', 'MIN = 3', "credentials: 'same-origin'", 'Pode digitar tudo normalmente', 'window.ConnectSuggest'] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
        self::assertStringNotContainsString('innerHTML = it.', $js, 'Texto do banco sempre passa pelo esc().');
        self::assertStringContainsString('.cx-suggest {', $this->file('container/shared/assets/connect-fields/connect-fields.css'));
    }

    public function testEveryPersonAndEmailFieldSuggests(): void
    {
        foreach (self::FIELDS as $path => $ids) {
            $html = $this->file($path);
            foreach ($ids as $id) {
                self::assertMatchesRegularExpression('/<input[^>]*' . preg_quote($id, '/') . '[^>]*data-cx-suggest="(person|email)"|<input[^>]*data-cx-suggest="(person|email)"[^>]*' . preg_quote($id, '/') . '/', $html, $path . ' ' . $id);
            }
        }
        $definitions = $this->file('source/Services/Erp/Registry/Definitions.php');
        self::assertStringContainsString("'suggest' => ['kind' => 'person', 'sources' => 'residents', 'fill_email' => 'email']", $definitions);
        self::assertStringContainsString("data-cx-suggest-", $this->file('container/erp/connect/v1/components/forms/registry-field.php'));
    }

    public function testHelpDeskNoLongerListsEveryAccount(): void
    {
        $html = $this->file('container/cms/studio/v1/components/tickets/home.php');
        self::assertStringNotContainsString('<select class="studio-select" name="requester_id">', $html, 'Lista com todas as contas (inclusive moradores) saiu.');
        self::assertStringContainsString('accountAllowed($user', $this->file('source/Controllers/Studio/Studio.php'));
        self::assertStringContainsString('accountAllowed($user', $this->file('source/Controllers/Erp/Connect/Tickets.php'));
    }

    public function testRouteAndContract(): void
    {
        self::assertStringContainsString('$route->get("/suggest/people", "PeopleSuggest:search", null, \Source\Support\Middleware\ApiAuthenticated::class);', $this->file('index.php'));
        self::assertStringContainsString('/suggest/people:', $this->file('docs/api/openapi.yaml'));
    }
}
