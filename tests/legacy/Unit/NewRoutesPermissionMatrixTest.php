<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Passada de qualidade dos ciclos 37 a 42 (ADR 0094): toda ação pública dos controllers novos passa por
 * autorização, e cada uma exige a permissão certa. Varredura estática do código, sem banco.
 */
final class NewRoutesPermissionMatrixTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../source/Controllers/Api/V1/';

    /** controller => [permissões que o arquivo precisa exigir] */
    private const REQUIRED = [
        'Assemblies' => ["'erp.access'", "'erp.assemblies.view'", "'erp.assemblies.manage'", "'erp.announcements.manage'", "'erp.agreements.view'"],
        'PaymentReports' => ["'erp.access'", "'erp.entries.view'", "'erp.agreements.view'", "'erp.bank_accounts.view'"],
        'AccessAdmin' => ["'erp.access'", "'erp.access_admin.view'", "'erp.access_admin.manage'", "'erp.partner.view'", "'erp.residents.view'"],
        'IntegrationStatus' => ["'erp.access'", "'erp.settings.view'"],
    ];

    public function testEveryPublicActionGoesThroughAuthorization(): void
    {
        foreach (array_keys(self::REQUIRED) as $controller) {
            foreach ($this->publicMethods($controller) as $method => $body) {
                self::assertMatchesRegularExpression('/\$this->(authorize|write|report)\(|Auth::user\(\)/', $body, "{$controller}::{$method} não passa por autorização.");
            }
        }
    }

    public function testEachControllerRequiresItsPermissions(): void
    {
        foreach (self::REQUIRED as $controller => $slugs) {
            $source = (string)file_get_contents(self::DIR . $controller . '.php');
            foreach ($slugs as $slug) {
                self::assertStringContainsString($slug, $source, "{$controller} não exige {$slug}.");
            }
        }
    }

    public function testSensitiveActionsAskForTheExtraPermission(): void
    {
        $assemblies = $this->publicMethods('Assemblies');
        self::assertStringContainsString("'erp.announcements.manage'", $assemblies['convene'], 'Convocar publica comunicado.');
        self::assertStringContainsString("'erp.agreements.view'", $assemblies['attendanceReport'], 'O valor vencido só com a inadimplência.');
        self::assertStringContainsString("'erp.agreements.view'", $assemblies['checkIn'], 'ADR 0095: o check-in também esconde o valor vencido.');
        $access = $this->publicMethods('AccessAdmin');
        self::assertStringContainsString("'erp.partner.view'", $access['partnerView']);
        self::assertStringNotContainsString('erp.access_admin', $access['partnerView'], 'A consulta do parceiro não exige papel de administrador.');
        self::assertStringContainsString("'erp.residents.view'", $access['invite']);
        self::assertStringContainsString("'erp.residents.view'", $access['invitations']);
        $reports = $this->publicMethods('PaymentReports');
        self::assertStringContainsString("'erp.agreements.view'", $reports['calculation']);
        self::assertStringContainsString("'erp.bank_accounts.view'", $reports['transfers']);
        // Escrita exige o manage: o write() do Assemblies e do AccessAdmin chama authorize com manage.
        foreach (['Assemblies' => 'erp.assemblies.manage', 'AccessAdmin' => 'erp.access_admin.manage'] as $controller => $manage) {
            $source = (string)file_get_contents(self::DIR . $controller . '.php');
            self::assertMatchesRegularExpression('/private function write\(.*?\{.*?' . preg_quote($controller === 'Assemblies' ? 'authorize(true' : $manage, '/') . '/s', $source, "{$controller}::write sem {$manage}.");
        }
    }

    /** @return array<string, string> nome => corpo das funções públicas (sem o construtor) */
    private function publicMethods(string $controller): array
    {
        $source = (string)file_get_contents(self::DIR . $controller . '.php');
        preg_match_all('/public function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $m) {
            if ($m[1] !== '__construct') {
                $out[$m[1]] = $m[2];
            }
        }
        self::assertNotEmpty($out, "{$controller} sem ações públicas lidas.");
        return $out;
    }
}
