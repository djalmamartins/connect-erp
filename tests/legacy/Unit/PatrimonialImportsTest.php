<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\Imports\PatrimonialImports;

/** ADR 0171: importação de lançamentos patrimoniais — prévia com erros, gravação tudo ou nada, idempotência e escopo. */
final class PatrimonialImportsTest extends TestCase
{
    private const HEADER = "data;descricao;valor;conta_debito;conta_credito;conta_bancaria;lancamento_origem\n";

    private PDO $pdo;
    /** @var list<string> */
    private array $files = [];

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
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER, transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT, updated_by INTEGER)',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER UNIQUE,
                reversal_reason TEXT, created_by INTEGER)",
            "CREATE TABLE erp_patrimonial_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER, idempotency_key TEXT, sha256 TEXT, original_name TEXT, content BLOB,
                status TEXT DEFAULT 'previewed', rows_count INTEGER, error_count INTEGER, summary_json TEXT, created_by INTEGER, applied_by INTEGER, applied_at TEXT,
                UNIQUE (condominium_id, idempotency_key))",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01'), (30, 3, 'Fora', 0, NULL)");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on) VALUES
            (2, 1, 'receita', 2, 10, 'Fundo de reserva out', 100000, '2026-10', '2026-10-10', '2026-10-12'), (8, 3, 'receita', 2, 30, 'Fora', 1, '2026-10', '2026-10-10', '2026-10-10')");
        $this->pdo->exec("INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (1, 91, '2025-01-01', 50000)");
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testPreviewShowsTheErrorOfEachLineAndNothingIsWritten(): void
    {
        $service = $this->service();
        $preview = $service->preview($this->user(), ['condominium_id' => 1], $this->csv(self::HEADER
            . "15/10/2026;Apropriação de outubro;300,00;9.2;8.2;Movimento;2\n"
            . "15/10/2026;Conta calculada;10,00;7.2;8.2;;\n"
            . "15/10/2026;Conta de receita;10,00;9.2;1.01;;\n"
            . "15/10/2026;Mesmas contas;10,00;8.2;8.2;;\n"
            . "15/12/2026;Data futura;10,00;9.2;8.2;;\n"
            . "15/10/2026;Valor ruim;-5;9.2;8.2;;\n"
            . "15/10/2026;Banco de outro;10,00;9.2;8.2;Fora;\n"
            . "15/10/2026;Origem de outro;10,00;9.2;8.2;;8\n"), '2026-11-10');
        self::assertSame([8, 7, 'previewed'], [$preview['rows_count'], $preview['error_count'], $preview['status']]);
        self::assertSame(['ok', 'error', 'error', 'error', 'error', 'error', 'error', 'error'], array_column($preview['preview'], 'status'));
        self::assertSame([2, 1, 4], [$preview['preview'][0]['line'], count($preview['preview'][1]['errors']), $preview['preview'][2]['line']], 'Linha da planilha (o cabeçalho é a 1).');
        self::assertStringContainsString('7.2 é calculada', $preview['preview'][1]['errors'][0]);
        self::assertStringContainsString('conta patrimonial analítica', $preview['preview'][2]['errors'][0]);
        self::assertStringContainsString('contas diferentes', $preview['preview'][3]['errors'][0]);
        self::assertSame(['bank_account_id' => 10, 'linked_entry_id' => 2], array_intersect_key($preview['preview'][0]['data'], array_flip(['bank_account_id', 'linked_entry_id'])));
        try {
            $service->apply($this->user(), $preview['id'], '2026-11-10');
            self::fail('Aplicou com erro.');
        } catch (DomainException) {   // 409
            self::addToAssertionCount(1);
        }
        self::assertSame([0, 'previewed'], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_patrimonial_entries')->fetchColumn(), $service->find($this->user(), $preview['id'])['status']]);
    }

    public function testApplyWritesAllInOneTransactionAndTheBalanceCloses(): void
    {
        $service = $this->service();
        $file = $this->csv(self::HEADER . "15/10/2026;Apropriação de outubro;300,00;9.2;8.2;Movimento;2\n2026-10-20;Transferência entre fundos;50,00;8.2;8.3;;\n");
        $preview = $service->preview($this->user(), ['condominium_id' => 1], $file, '2026-11-10');
        self::assertSame(['rows' => 2, 'errors' => 0, 'ok' => 2, 'amount_cents' => 35000], $preview['summary']);
        $applied = $service->apply($this->user(), $preview['id'], '2026-11-10');
        self::assertSame(['applied', 2], [$applied['status'], $applied['summary']['applied']['entries']]);
        $rows = $this->pdo->query('SELECT debit_account_id, credit_account_id, amount_cents, import_id, created_by FROM erp_patrimonial_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([[92, 82, 30000, $preview['id'], 7], [82, 83, 5000, $preview['id'], 7]],
            array_map(static fn (array $r): array => array_map('intval', array_values($r)), $rows));
        $sheet = (new BalanceSheet($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(), 1, '2026-10-31');
        self::assertSame([25000, 5000, 0], [array_column($sheet['liabilities']['rows'], 'amount_cents', 'code')['8.2'],
            array_column($sheet['liabilities']['rows'], 'amount_cents', 'code')['8.3'], $sheet['diferenca']]);

        // Tudo ou nada: com a competência fechada depois da prévia, a aplicação revalida e não grava nenhuma linha.
        $other = $service->preview($this->user(), ['condominium_id' => 1], $this->csv(self::HEADER . "16/10/2026;Outro;1,00;9.2;8.2;;\n17/10/2026;Mais um;1,00;9.2;8.2;;\n"), '2026-11-10');
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed')");
        try {
            $service->apply($this->user(), $other['id'], '2026-11-10');
            self::fail('Aplicou em competência fechada.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([2, 'previewed'], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_patrimonial_entries')->fetchColumn(), $service->find($this->user(), $other['id'])['status']]);
    }

    public function testIdempotency(): void
    {
        $service = $this->service();
        $content = self::HEADER . "15/10/2026;Apropriação;300,00;9.2;8.2;;\n";
        $first = $service->preview($this->user(), ['condominium_id' => 1], $this->csv($content), '2026-11-10');
        $again = $service->preview($this->user(), ['condominium_id' => 1], $this->csv($content), '2026-11-10');
        self::assertSame($first['id'], $again['id'], 'O mesmo arquivo em prévia é revalidado no mesmo registro.');
        self::assertStringStartsWith('sha256:', $first['idempotency_key']);
        $service->apply($this->user(), $first['id'], '2026-11-10');
        foreach ([fn () => $service->preview($this->user(), ['condominium_id' => 1], $this->csv($content), '2026-11-10'), fn () => $service->apply($this->user(), $first['id'], '2026-11-10')] as $call) {
            try {
                $call();
                self::fail('Gravou duas vezes.');
            } catch (DomainException) {   // 409
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_patrimonial_entries')->fetchColumn());

        $keyed = $service->preview($this->user(), ['condominium_id' => 1, 'idempotency_key' => 'lote-2026-10-a'], $this->csv(self::HEADER . "16/10/2026;Outra;1,00;9.2;8.2;;\n"), '2026-11-10');
        self::assertSame('lote-2026-10-a', $keyed['idempotency_key']);
        try {
            $service->preview($this->user(), ['condominium_id' => 1, 'idempotency_key' => 'lote-2026-10-a'], $this->csv(self::HEADER . "17/10/2026;Diferente;2,00;9.2;8.2;;\n"), '2026-11-10');
            self::fail('Aceitou a mesma chave com outro arquivo.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(['abcdefgh', 'sha256:x'], [PatrimonialImports::key('abcdefgh', 'x'), PatrimonialImports::key('curta', 'x')]);
    }

    public function testScopeAndValidation(): void
    {
        $service = $this->service();
        $file = $this->csv(self::HEADER . "15/10/2026;Apropriação;300,00;9.2;8.2;;\n");
        $this->expectScope(fn () => $service->preview($this->user(), ['condominium_id' => 3], $file, '2026-11-10'));
        $this->pdo->exec("INSERT INTO erp_patrimonial_imports (id, condominium_id, idempotency_key, sha256, original_name, content, rows_count, error_count) VALUES (90, 3, 'k', 'x', 'f.csv', 'x', 0, 0)");
        $this->expectScope(fn () => $service->find($this->user(), 90));
        $this->expectScope(fn () => $service->apply($this->user(), 90, '2026-11-10'));
        $this->expectScope(fn () => $service->apply($this->user(), 999, '2026-11-10'));
        foreach ([
            [['condominium_id' => 1], $this->csv("data;descricao;valor\n15/10/2026;x;1,00\n")],   // faltam colunas
            [['condominium_id' => 1], $this->csv(self::HEADER)],                                  // sem linhas
            [['condominium_id' => null], $file],
            [['condominium_id' => 1], ['error' => UPLOAD_ERR_NO_FILE]],
            [['condominium_id' => 1], $this->csv(self::HEADER . "15/10/2026;x;1,00;9.2;8.2;;\n", 'lote.txt')],
        ] as [$input, $upload]) {
            try {
                $service->preview($this->user(), $input, $upload, '2026-11-10');
                self::fail('Aceitou ' . json_encode($input));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/PatrimonialImports.php');
        foreach (["'erp.imports.view'", "'erp.imports.manage'", "'erp.entries.manage'", 'HTTP_IDEMPOTENCY_KEY', 'catch (CondominiumAccessDenied)', 'JsonResponse::send(409'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    private function service(): PatrimonialImports
    {
        return new PatrimonialImports($this->pdo, new CondominiumScope($this->pdo), true);
    }

    /** @return array{name:string, tmp_name:string, error:int} */
    private function csv(string $content, string $name = 'lote.csv'): array
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'pimp');
        file_put_contents($file, $content);
        $this->files[] = $file;
        return ['name' => $name, 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK];
    }

    private function expectScope(callable $call): void
    {
        try {
            $call();
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied) {   // 404
            self::addToAssertionCount(1);
        }
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
