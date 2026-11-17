<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\Registry;
use Source\Services\Erp\Registry\Definitions;
use Source\Support\Erp\CondominiumContext;

/**
 * XSS nas telas do ERP (ADR 0344): grava um texto de teste com HTML em toda coluna de texto dos cadastros e do condomínio e
 * renderiza no servidor todas as listagens e fichas de cadastro, todas as páginas do catálogo e as visões gerais. O texto só pode
 * aparecer escapado; a marca crua "<i data-xss" na resposta é falha.
 *
 * O texto também fecha aspas e um <script>, para pegar a saída em atributo e dentro de script.
 */
final class ErpScreensXssCanaryIntegrationTest extends TestCase
{
    use ScopeFixtures;

    private const CANARY = '"\'></script><i data-xss>x';
    private const MARK = '<i data-xss>';

    protected function tearDown(): void
    {
        // O contexto do condomínio é estático: não pode vazar para o próximo teste.
        CondominiumContext::reset();
        $_GET = [];
        parent::tearDown();
    }

    public function testServerRenderedScreensEscapeStoredText(): void
    {
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([self::CANARY, 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 5, 'first_name' => self::CANARY]);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Dev', 'developer', 10)");
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'developer'")->execute([$userId]);
        $this->pdo->exec('UPDATE settings SET access_erp = 1');
        $_SESSION['authUser'] = $userId;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        CondominiumContext::use([['id' => $condominium, 'name' => self::CANARY]], $condominium);

        $unit = $this->scopeRow('erp_units', $this->texts('erp_units'), ['condominium_id' => $condominium]);
        $refs = ['condominium_id' => $condominium, 'unit_id' => $unit, 'created_by' => $userId, 'user_id' => $userId];
        $ids = ['erp_units' => $unit];
        foreach (Definitions::all() as $definition) {
            $table = (string)$definition['table'];
            $ids[$table] ??= $this->scopeRow($table, $this->texts($table) + ['deleted_at' => null, 'status' => 'active'], $refs);
        }

        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        $screens = [];
        foreach (Definitions::all() as $slug => $definition) {
            $screens['cadastro ' . $slug] = static fn () => (new Registry())->index($slug);
            // Contas a pagar é a mesma tabela de lançamentos, só com despesa.
            $id = (string)($slug === 'payables'
                ? $this->scopeRow('erp_entries', $this->texts('erp_entries') + ['nature' => 'despesa', 'deleted_at' => null, 'status' => 'active'], $refs)
                : $ids[(string)$definition['table']]);
            $screens['ficha ' . $slug] = static fn () => (new Registry())->edit($slug, ['id' => $id]);
        }
        foreach ($catalog['pages'] as $pageId => $page) {
            $screens['página ' . $pageId] = static fn () => (new Panel())->page($pageId);
        }
        foreach (array_keys($catalog['groups']) as $group) {
            $screens['visão ' . $group] = static fn () => (new Panel())->hub($group);
        }

        $raw = [];
        $broken = [];
        $shown = [];
        foreach ($screens as $name => $render) {
            \Source\Support\Access::clear();
            $_GET = [];
            ob_start();
            try {
                $render();
                $html = (string)ob_get_clean();
            } catch (\Throwable $error) {
                ob_end_clean();
                $broken[] = $name . ': ' . $error->getMessage();
                continue;
            }
            if (str_contains($html, self::MARK)) {
                $raw[] = $name;
            }
            if (str_contains($html, htmlspecialchars(self::MARK, ENT_QUOTES, 'UTF-8'))) {
                $shown[$name] = substr_count($html, htmlspecialchars(self::MARK, ENT_QUOTES, 'UTF-8'));
            }
        }
        self::assertGreaterThan(150, count($screens));
        self::assertSame([], $broken, 'Tela quebrou com o texto de teste.');
        // A moldura (barra do condomínio e usuário) mostra o texto em todas; as listagens de cadastro precisam mostrar mais
        // que ela, senão a linha gravada nem chegou à tela e o teste não provaria nada.
        $frame = min($shown);
        $empty = array_keys(array_filter($shown, static fn (int $n, string $name): bool => (str_starts_with($name, 'cadastro ') || str_starts_with($name, 'ficha ')) && $n <= $frame, ARRAY_FILTER_USE_BOTH));
        self::assertSame([], $empty, 'Listagem sem a linha de teste.');
        self::assertSame([], $raw, 'Texto gravado saiu sem escapar nessas telas.');
    }

    /** @return array<string, string> todas as colunas de texto da tabela com o texto de teste (cortado no tamanho da coluna). */
    private function texts(string $table): array
    {
        $stmt = $this->pdo->prepare("SELECT column_name, character_maximum_length FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%GENERATED%'
              AND data_type IN ('varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext')");
        $stmt->execute([$table]);
        $values = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $max = (int)($c['character_maximum_length'] ?? 0);
            if ($max >= strlen(self::CANARY)) {
                $values[(string)$c['column_name']] = self::CANARY;
            }
        }
        return $values;
    }
}
