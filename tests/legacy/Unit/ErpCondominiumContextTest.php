<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\Erp\CondominiumContext;

/**
 * ADR 0030: o condomínio da barra superior vai para a URL do ERP como slug e vale para
 * todas as telas. Testes sem banco: o escopo do usuário entra como lista de opções.
 */
final class ErpCondominiumContextTest extends TestCase
{
    private const RESERVED = ['dashboard', 'cadastros', 'condominios', 'operacional', 'login', 'erp'];
    private const OPTIONS = [
        ['id' => 3, 'name' => 'Residencial Exemplo'],
        ['id' => 7, 'name' => 'Edifício São João'],
    ];

    private string $uri;

    protected function setUp(): void
    {
        $this->uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        CondominiumContext::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = $this->uri;
        CondominiumContext::reset();
    }

    public function testSlugifyRemovesAccentsAndPunctuation(): void
    {
        self::assertSame('residencial-exemplo', CondominiumContext::slugify('Residencial Exemplo'));
        self::assertSame('edificio-sao-joao', CondominiumContext::slugify('  Edifício São João! '));
        self::assertSame('torre-a-e-b', CondominiumContext::slugify('Torre A & B'));
    }

    public function testDuplicateReservedAndNumericSlugsGetTheId(): void
    {
        $slugs = array_column(CondominiumContext::withSlugs([
            ['id' => 1, 'name' => 'Solar'], ['id' => 2, 'name' => 'Solar'],
            ['id' => 5, 'name' => 'Dashboard'], ['id' => 6, 'name' => 'Todos'], ['id' => 8, 'name' => '123'], ['id' => 9, 'name' => '***'],
        ], self::RESERVED), 'slug', 'id');
        self::assertSame([1 => 'solar-1', 2 => 'solar-2', 5 => 'dashboard-5', 6 => 'todos-6', 8 => '123-8', 9 => '9'], $slugs);
    }

    public function testSplitSeparatesSlugButNeverARoute(): void
    {
        self::assertSame(['residencial-exemplo', '/cadastros/sindico-e-conselho'], CondominiumContext::split('/residencial-exemplo/cadastros/sindico-e-conselho', self::RESERVED));
        self::assertSame(['residencial-exemplo', '/'], CondominiumContext::split('/residencial-exemplo', self::RESERVED));
        self::assertSame([null, '/cadastros/sindico-e-conselho'], CondominiumContext::split('/cadastros/sindico-e-conselho', self::RESERVED));
        self::assertSame([null, '/login'], CondominiumContext::split('/login', self::RESERVED));
        self::assertSame([null, '/'], CondominiumContext::split('/', self::RESERVED));
    }

    public function testPathForAddsSlugOnlyToContextAwarePaths(): void
    {
        $condo = ['id' => 3, 'name' => 'Residencial Exemplo', 'slug' => 'residencial-exemplo'];
        self::assertSame('/residencial-exemplo/cadastros', CondominiumContext::pathFor('/cadastros', $condo));
        self::assertSame('/residencial-exemplo', CondominiumContext::pathFor('/', $condo));
        self::assertSame('/login', CondominiumContext::pathFor('/login', $condo));
        self::assertSame('/seguranca', CondominiumContext::pathFor('/seguranca', $condo));
        self::assertSame('/contexto/condominio', CondominiumContext::pathFor('/contexto/condominio', $condo));
        self::assertSame('/cadastros', CondominiumContext::pathFor('/cadastros', null));
    }

    public function testSlugInUrlSelectsCondominiumAndRewritesUri(): void
    {
        $_SERVER['REQUEST_URI'] = '/residencial-exemplo/cadastros/sindico-e-conselho?q=ana';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/cadastros/sindico-e-conselho?q=ana', $_SERVER['REQUEST_URI']);
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, null, 'GET', ['q' => 'ana']));
        self::assertSame(3, CondominiumContext::id());
        self::assertSame('/residencial-exemplo/dashboard', CondominiumContext::path('/dashboard'));
        self::assertSame('/cadastros/sindico-e-conselho', CondominiumContext::relativePath());
    }

    public function testSlugUnderErpPrefixOnMainHost(): void
    {
        $_SERVER['REQUEST_URI'] = '/erp/edificio-sao-joao/dashboard';
        CondominiumContext::boot(self::RESERVED, '/erp');
        self::assertSame('/erp/dashboard', $_SERVER['REQUEST_URI']);
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, null, 'GET', []));
        self::assertSame(7, CondominiumContext::id());
    }

    public function testRequestsOutsideErpPrefixAreIgnored(): void
    {
        $_SERVER['REQUEST_URI'] = '/studio/residencial-exemplo';
        CondominiumContext::boot(self::RESERVED, '/erp');
        self::assertFalse(CondominiumContext::booted());
        self::assertSame('/studio/residencial-exemplo', $_SERVER['REQUEST_URI']);
    }

    public function testSlugOutsideScopeFallsBackTo404(): void
    {
        $_SERVER['REQUEST_URI'] = '/condominio-de-outro/dashboard';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, null, 'GET', []));
        self::assertNull(CondominiumContext::current());
        // URI original de volta: nenhuma rota casa e o router responde 404.
        self::assertSame('/condominio-de-outro/dashboard', $_SERVER['REQUEST_URI']);
    }

    public function testOldUrlWithoutSlugRedirectsToSessionCondominium(): void
    {
        $_SERVER['REQUEST_URI'] = '/cadastros/sindico-e-conselho?situacao=active';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/residencial-exemplo/cadastros/sindico-e-conselho?situacao=active', CondominiumContext::resolve(self::OPTIONS, 3, 'GET', ['situacao' => 'active']));
    }

    public function testNoRedirectForPostOrContextFreePaths(): void
    {
        $_SERVER['REQUEST_URI'] = '/cadastros/sindico-e-conselho';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, 3, 'POST', []));
        self::assertSame(3, CondominiumContext::id());

        CondominiumContext::reset();
        $_SERVER['REQUEST_URI'] = '/seguranca';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, 3, 'GET', []));
        // Os links da tela continuam com o condomínio.
        self::assertSame('/residencial-exemplo/dashboard', CondominiumContext::path('/dashboard'));
    }

    public function testSessionCondominiumOutsideScopeIsDropped(): void
    {
        $_SERVER['REQUEST_URI'] = '/dashboard';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertNull(CondominiumContext::resolve(self::OPTIONS, 99, 'GET', []));
        self::assertNull(CondominiumContext::current());
    }

    public function testTodosSegmentRedirectsToUrlWithoutSlug(): void
    {
        $_SERVER['REQUEST_URI'] = '/todos/cadastros/veiculos';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/cadastros/veiculos', CondominiumContext::resolve(self::OPTIONS, 3, 'GET', []));
    }

    public function testFilterParameterSwitchesTheTopBarCondominium(): void
    {
        $_SERVER['REQUEST_URI'] = '/residencial-exemplo/cadastros/veiculos?condominio=7&q=abc';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/edificio-sao-joao/cadastros/veiculos?q=abc', CondominiumContext::resolve(self::OPTIONS, 3, 'GET', ['condominio' => '7', 'q' => 'abc']));
    }

    public function testFilterAllClearsTheCondominium(): void
    {
        $_SERVER['REQUEST_URI'] = '/residencial-exemplo/cadastros/veiculos?condominio=';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/cadastros/veiculos', CondominiumContext::resolve(self::OPTIONS, 3, 'GET', ['condominio' => '']));
    }

    public function testFilterOutsideScopeKeepsCurrentCondominium(): void
    {
        $_SERVER['REQUEST_URI'] = '/residencial-exemplo/cadastros/veiculos?condominio=99';
        CondominiumContext::boot(self::RESERVED, '');
        self::assertSame('/residencial-exemplo/cadastros/veiculos', CondominiumContext::resolve(self::OPTIONS, 3, 'GET', ['condominio' => '99']));
    }

    public function testRoutesKeepTheirFirstSegmentsReserved(): void
    {
        $router = new \Source\Core\Router('https://erp.connect.lab', ':');
        $router->group('/erp');
        $router->get('/cadastros/veiculos', static fn () => null);
        $router->group(null);
        $router->get('/dashboard', static fn () => null);
        $router->get('/{id}', static fn () => null);
        $segments = $router->firstSegments();
        sort($segments);
        self::assertSame(['cadastros', 'dashboard', 'erp'], $segments);
    }
}
