<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Core\View;
use Source\Models\User;

/**
 * A1/A2: o ERP resolve para container/erp/connect/v1, segue o contrato de tema e
 * as páginas de Condomínios renderizam com escape e estados vazio/erro.
 */
final class ErpThemeTest extends TestCase
{
    public function testErpResolvesToContractThemeForLegacyAndCurrentAliases(): void
    {
        foreach (['connect', 'default', 'CONNECT'] as $alias) {
            self::assertStringEndsWith('/container/erp/connect/v1', moves_container_path('erp', $alias));
        }
        self::assertStringEndsWith('/container/erp/connect/v1/assets/css/erp.css', moves_container_url('erp', 'default', '/assets/css/erp.css'));
    }

    public function testInvalidThemeNameCannotEscapeErpContainer(): void
    {
        foreach (['../../source', 'connect/../../x', ''] as $theme) {
            self::assertStringEndsWith('/container/erp/connect/v1', moves_container_path('erp', $theme));
        }
    }

    public function testThemeRootContainsOnlyContractPhpFiles(): void
    {
        $root = moves_container_path('erp', 'connect');
        $php = array_map('basename', glob($root . '/*.php') ?: []);
        sort($php);
        self::assertSame(['default.php', 'routes.php'], $php);
        self::assertDirectoryDoesNotExist($root . '/layouts');
        self::assertFileExists($root . '/assets/css/erp.css');
        self::assertFileExists($root . '/assets/js/erp.js');
        // Minify/Erp.php só publica o design system do ERP (sem jQuery/Highcharts) e só quando a fonte muda.
        self::assertStringNotContainsString('jquery', strtolower((string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Minify/Erp.php')));
    }

    public function testRoutesRegisterCondominiumModuleUnderErpGroup(): void
    {
        $route = new class {
            public array $calls = [];
            public function __call(string $name, array $args): void { $this->calls[] = [$name, ...$args]; }
        };
        require moves_container_path('erp', 'connect') . '/routes.php';

        self::assertContains(['namespace', 'Source\\Controllers\\Erp\\Connect'], $route->calls);
        self::assertContains(['group', '/erp'], $route->calls);
        self::assertContains(['get', '/condominios', 'Condominiums:index'], $route->calls);
        self::assertContains(['get', '/condominios/{id}', 'Condominiums:show'], $route->calls);
        self::assertSame(['group', null], end($route->calls), 'O grupo /erp não pode vazar para rotas registradas depois.');
        self::assertTrue(class_exists(\Source\Controllers\Erp\Connect\Condominiums::class));
    }

    public function testListEscapesDataFormatsCnpjAndHidesUnauthorizedNavigation(): void
    {
        $html = $this->render('pages/condominiums/index', ['condominiums' => [
            ['id' => 7, 'name' => '<script>alert(1)</script> Residencial', 'document' => '12345678000195', 'status' => 'active'],
        ]], ['erp.condominiums.view']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('12.345.678/0001-95', $html);
        self::assertStringContainsString('/erp/condominios/7', $html);
        self::assertStringContainsString('aria-current="page"', $html);

        $withoutPermission = $this->render('pages/condominiums/index', ['condominiums' => []], ['erp.access']);
        self::assertStringNotContainsString('<a href="' . url('/erp/condominios') . '"', $withoutPermission, 'Sem erp.condominiums.view o item some do menu.');
    }

    public function testEmptyAndErrorStates(): void
    {
        self::assertStringContainsString('Nenhum condomínio disponível', $this->render('pages/condominiums/index', ['condominiums' => []]));

        $html = $this->render('pages/errors/state', ['title' => 'Acesso negado', 'description' => '<b>x</b>']);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);

        $show = $this->render('pages/condominiums/show', ['condominium' => ['id' => 3, 'name' => 'Ed. "Aurora"', 'document' => null, 'status' => 'inactive']]);
        self::assertStringContainsString('Ed. &quot;Aurora&quot;', $show);
        self::assertStringContainsString('Inativo', $show);
        self::assertStringContainsString('—', $show);
    }

    public function testFormatCnpj(): void
    {
        self::assertSame('12.345.678/0001-95', format_cnpj('12345678000195'));
        self::assertSame('—', format_cnpj(null));
        self::assertSame('123', format_cnpj('123'));
    }

    private function render(string $template, array $data, array $permissions = ['erp.access', 'erp.condominiums.view']): string
    {
        // Mesma moldura das rotas: Panel::layoutData() monta sidebar, navbar e breadcrumbs.
        $user = new class($permissions) extends User {
            public function __construct(private array $granted) { parent::__construct(); $this->id = 1; $this->first_name = 'Ana'; $this->last_name = 'Teste'; $this->email = 'ana@example.invalid'; }
            public function can(string $permission): bool { return in_array($permission, $this->granted, true); }
        };
        $frame = (new Panel())->layoutData($user, 'AP-004', $data, 'Condomínios');
        return (new View(moves_container_path('erp', 'connect') . '/'))->render($template, $frame);
    }
}
