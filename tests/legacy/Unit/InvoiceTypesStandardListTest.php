<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Controllers\Api\V1\Registry;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0163: GET /api/v1/invoice-types?condominium_id=N traz os tipos padrão (condominium_id NULL) e os do
 * condomínio N; o do condomínio com o mesmo nome prevalece; nada de outro condomínio; N fora do escopo = 404.
 */
final class InvoiceTypesStandardListTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_invoice_types (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, chart_account_id INTEGER, recurrence TEXT,
                default_amount_cents INTEGER, notes TEXT, status TEXT DEFAULT 'active', created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
    }

    public function testOnlyStandardTypesAreReturned(): void
    {
        $this->type(null, 'Taxa condominial');
        $this->type(null, 'Fundo de reserva');
        $this->type(null, 'Taxa extra antiga', 'inactive');
        self::assertSame(['Fundo de reserva', 'Taxa condominial'], $this->names(1), 'Só padrão: a tela de preparar fatura não fica vazia.');
    }

    public function testStandardPlusCondominiumWithOverrideAndNoLeak(): void
    {
        $this->type(null, 'Taxa condominial');
        $this->type(null, 'Fundo de reserva');
        $this->type(1, 'Taxa condominial');        // sobrepõe o padrão de mesmo nome
        $this->type(1, 'Água individual');
        $this->type(2, 'Taxa do Bosque');          // outro condomínio do escopo
        $this->type(3, 'Taxa de Fora');            // fora do escopo
        $list = $this->list(1);
        self::assertEqualsCanonicalizing(['Água individual', 'Fundo de reserva', 'Taxa condominial'], array_column($list, 'name'), 'A ordem fica com o collation do banco.');
        self::assertCount(3, $list, 'Sem o padrão duplicado.');
        $taxa = array_values(array_filter($list, static fn (array $r): bool => $r['name'] === 'Taxa condominial'))[0];
        self::assertSame(1, (int)$taxa['condominium_id'], 'O tipo do condomínio prevalece sobre o padrão de mesmo nome.');
        self::assertSame(['Fundo de reserva', 'Taxa condominial', 'Taxa do Bosque'], $this->names(2), 'O condomínio 2 vê o padrão e os seus, sem a sobreposição do 1.');
        foreach ([1, 2] as $condominium) {
            self::assertNotContains('Taxa de Fora', $this->names($condominium), 'Não vaza tipo de outro condomínio.');
        }
        $screen = (new RegistryService($this->pdo, new CondominiumScope($this->pdo)))->list($this->user(), 'invoice_types', ['condominium_id' => 1, 'include_standard' => true]);
        self::assertCount(4, $screen['data'], 'Na tela de cadastro (sem prefer_condominium) o padrão e o do condomínio aparecem, para editar o padrão.');
        $only = (new RegistryService($this->pdo, new CondominiumScope($this->pdo)))->list($this->user(), 'invoice_types', ['condominium_id' => 1]);
        self::assertEqualsCanonicalizing(['Água individual', 'Taxa condominial'], array_column($only['data'], 'name'), 'include_standard=0: só o do condomínio, como antes.');
    }

    public function testApiFiltersAndOutOfScope404(): void
    {
        $definition = RegistryService::definition('invoice_types');
        $allowed = static fn (int $id): bool => in_array($id, [1, 2], true);
        self::assertSame(['include_standard' => true, 'prefer_condominium' => true], Registry::standardFilters($definition, '1', null, $allowed));
        self::assertSame(['include_standard' => false, 'prefer_condominium' => false], Registry::standardFilters($definition, '1', '0', $allowed));
        self::assertNull(Registry::standardFilters($definition, '3', null, $allowed), 'Fora do escopo = 404.');
        self::assertSame([], Registry::standardFilters($definition, null, null, $allowed), 'Sem condominium_id: comportamento de antes.');
        self::assertSame([], Registry::standardFilters($definition, 'abc', null, $allowed));
        self::assertSame([], Registry::standardFilters(RegistryService::definition('residents'), '3', null, $allowed), 'Cadastro de escopo obrigatório: nada muda.');
        self::assertSame('name', $definition['override']);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/Registry.php');
        self::assertStringContainsString('if ($standard === null) {' . "\n" . '            $this->notFound();', $controller);
    }

    private function type(?int $condominium, string $name, string $status = 'active'): void
    {
        $this->pdo->prepare("INSERT INTO erp_invoice_types (condominium_id, name, chart_account_id, recurrence, status) VALUES (?, ?, 1, 'mensal', ?)")
            ->execute([$condominium, $name, $status]);
    }

    /** O que a API devolve com ?status=active&condominium_id=N. */
    private function list(int $condominium): array
    {
        $filters = Registry::standardFilters(RegistryService::definition('invoice_types'), (string)$condominium, null, static fn (int $id): bool => in_array($id, [1, 2], true));
        return (new RegistryService($this->pdo, new CondominiumScope($this->pdo)))->list($this->user(), 'invoice_types', (array)$filters + ['condominium_id' => $condominium, 'status' => 'active'])['data'];
    }

    /** @return list<string> */
    private function names(int $condominium): array
    {
        return array_column($this->list($condominium), 'name');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
