<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\Imports\PatrimonialImports;
use Source\Services\Erp\Investments;
use Source\Services\Erp\PatrimonialEntries;

/**
 * ADR 0172: coerência contábil dos ADRs 0165–0171 num cenário de ponta a ponta e os testes das correções da
 * revisão de segurança. Unit com SQLite: não há banco *_test acessível sem Docker.
 */
final class AccountingCoherenceTest extends TestCase
{
    private const TODAY = '2026-11-10';

    private PDO $pdo;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = static fn (): string => self::TODAY . ' 10:00:00';
        $this->pdo instanceof \Pdo\Sqlite ? $this->pdo->createFunction('NOW', $now, 0) : $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, level INTEGER)',
            'CREATE TABLE access_user_roles (user_id INTEGER PRIMARY KEY, role_id INTEGER, assigned_by INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, group_name TEXT, description TEXT)',
            'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER, PRIMARY KEY (role_id, permission_id))',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT, assigned_by INTEGER, PRIMARY KEY (user_id, permission_id))',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, status TEXT DEFAULT 'confirmed')",
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER, granted_by INTEGER, PRIMARY KEY (user_id, condominium_id))',
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER DEFAULT 0, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, description TEXT, status TEXT DEFAULT 'active', created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
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
        $this->pdo->exec("INSERT INTO users (id, first_name, email) VALUES (7, 'Ana', 'ana@x.test'), (10, 'Duda', 'ops@x.test')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '1.02', 'Taxa de fundo de reserva', 'receita', 'analitica'), (4, NULL, '1.03', 'Rendimentos de aplicação', 'receita', 'analitica'),
            (5, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (6, NULL, '2.01', 'Energia', 'despesa', 'analitica'), (8, NULL, '2.02', 'IR sobre aplicação', 'despesa', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, kind, opening_balance_cents, opened_on) VALUES
            (10, 1, 'Movimento', 'corrente', 100000, '2025-01-01'), (11, 1, 'CDB', 'aplicacao', 0, '2025-01-01')");
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testEndToEndYearKeepsEveryReportCoherent(): void
    {
        $scope = new CondominiumScope($this->pdo);
        $user = $this->user();
        // Saldo inicial do PL igual ao saldo inicial do banco (AP-161).
        (new ChartOpenings($this->pdo, $scope))->set($user, 1, 91, '2025-01-01', 100000);
        $this->entries([
            [1, 'receita', 2, 10, 'Taxa outubro', 200000, '2025-10', '2025-10-10', '2025-10-10'],
            [2, 'receita', 3, 10, 'Taxa de fundo de reserva', 50000, '2025-10', '2025-10-10', '2025-10-10'],
            [3, 'receita', 2, null, 'Taxa novembro (em aberto)', 80000, '2025-11', '2025-11-10', null],
            [4, 'despesa', 6, 10, 'Energia outubro', 60000, '2025-10', '2025-10-20', '2025-10-20'],
            [5, 'despesa', 6, null, 'Energia novembro (a pagar)', 30000, '2025-11', '2025-11-05', null],
        ]);
        $patrimonial = new PatrimonialEntries($this->pdo, $scope);
        $patrimonial->create($user, ['condominium_id' => 1, 'entry_date' => '2025-10-10', 'description' => 'Apropriação ao fundo', 'amount_cents' => 50000,
            'debit_account_id' => 92, 'credit_account_id' => 82, 'linked_entry_id' => 2, 'bank_account_id' => 10], self::TODAY);
        $patrimonial->create($user, ['condominium_id' => 1, 'entry_date' => '2025-10-25', 'description' => 'Uso do fundo na energia', 'amount_cents' => 20000,
            'debit_account_id' => 82, 'credit_account_id' => 92, 'linked_entry_id' => 4], self::TODAY);
        $wrong = $patrimonial->create($user, ['condominium_id' => 1, 'entry_date' => '2025-12-10', 'description' => 'Lançado por engano', 'amount_cents' => 7000,
            'debit_account_id' => 82, 'credit_account_id' => 83], self::TODAY);
        $patrimonial->reverse($user, $wrong['id'], 'Lançado por engano', '2025-12-11', self::TODAY);

        $investments = new Investments($this->pdo, $scope);
        $investments->link($user, 11, 10);
        $before = $investments->positions($user, 1, '2025-10-31')['totals']['total_cents'];
        $investments->apply($user, 11, ['amount_cents' => 100000, 'transferred_on' => '2025-11-01']);
        self::assertSame($before, $investments->positions($user, 1, '2025-11-01')['totals']['total_cents'], 'Aplicar não muda o total.');
        $this->entries([
            [6, 'receita', 4, 11, 'Rendimento', 1200, '2025-11', '2025-11-30', '2025-11-30'],
            [7, 'despesa', 8, 11, 'IR sobre o rendimento', 180, '2025-11', '2025-11-30', '2025-11-30'],
        ]);
        $investments->redeem($user, 11, ['amount_cents' => 30000, 'transferred_on' => '2025-12-01']);
        $mistake = $investments->apply($user, 11, ['amount_cents' => 5000, 'transferred_on' => '2025-12-05']);
        (new EntryShortcuts($this->pdo, $scope))->cancelTransfer($user, $mistake['transfer']['id'], 'Aplicação em dobro');

        $import = new PatrimonialImports($this->pdo, $scope, true);
        $preview = $import->preview($user, ['condominium_id' => 1], $this->csv("data;descricao;valor;conta_debito;conta_credito\n15/12/2025;Transferência entre fundos;50,00;8.2;8.3\n"), self::TODAY);
        $import->apply($user, $preview['id'], self::TODAY);

        $accounting = new AccountingReports($this->pdo, $scope);
        $dre = $accounting->incomeStatement($user, 1, '2025-01-01', '2025-12-31');
        self::assertSame([331200, 90180, 241020], [$dre['totals']['revenue_cents'], $dre['totals']['expense_cents'], $dre['totals']['result_cents']],
            'DRE do exercício: rendimento e IR entram; transferências e lançamentos patrimoniais não.');

        $sheet = (new BalanceSheet($this->pdo, $scope))->build($user, 1, '2025-12-31');
        $assets = array_column($sheet['assets']['rows'], null, 'code');
        $liabilities = array_column($sheet['liabilities']['rows'], 'amount_cents', 'code');
        $equity = array_column($sheet['equity']['rows'], 'amount_cents', 'code');
        self::assertSame([0, true], [$sheet['diferenca'], $sheet['balanced']], 'O balanço fecha.');
        self::assertSame([291020, 220000, 71020, 80000], [$assets['7.1']['amount_cents'], $assets['7.1']['checking_cents'], $assets['7.1']['investments_cents'], $assets['7.2']['amount_cents']]);
        self::assertSame(['8.1' => 30000, '8.2' => 25000, '8.3' => 5000], array_intersect_key($liabilities, array_flip(['8.1', '8.2', '8.3'])), 'O estorno anulou o lançamento errado.');

        $ledger = $accounting->ledger($user, 1, '2025-01-01', '2025-12-31');
        $closing = array_column($ledger['accounts'], 'closing_cents', 'code');
        self::assertSame($equity['9.2'], $dre['totals']['result_cents'] + $closing['9.2'], '9.2 = DRE do exercício + movimentos patrimoniais na 9.2.');
        self::assertSame([$liabilities['8.2'], $liabilities['8.3'], $equity['9.1']], [$closing['8.2'], $closing['8.3'], $closing['9.1']], 'Razão das contas patrimoniais = balanço.');

        $bank = $accounting->bankLedger($user, 1, '2025-01-01', '2025-12-31');
        self::assertSame($assets['7.1']['amount_cents'], $bank['totals']['closing_cents'], 'Razão bancário = Disponível.');
        $positions = array_column($investments->positions($user, 1, '2025-12-31')['accounts'], 'closing_cents', 'bank_account_id');
        self::assertSame($positions, array_column($bank['accounts'], 'closing_cents', 'bank_account_id'), 'Razão bancário = conciliação de cada conta.');
        $transfers = array_merge(...array_map(static fn (array $a): array => array_filter($a['lines'], static fn (array $l): bool => $l['source'] === 'transfer'), $bank['accounts']));
        self::assertSame(array_sum(array_column($transfers, 'debit_cents')), array_sum(array_column($transfers, 'credit_cents')), 'Transferências somam zero.');
        self::assertNotContains($mistake['transfer']['id'], array_column($transfers, 'id'), 'A aplicação cancelada não aparece.');

        // Fundos da prestação de contas (ADR 0177): outubro tem a apropriação e o uso do fundo; dezembro, o estorno
        // e a importação em 8.3. O saldo final de cada fundo bate com o Balanço no último dia do mês.
        $october = Accountability::funds($accounting->ledger($user, 1, '2025-10-01', '2025-10-31', 'competence', '8'));
        self::assertSame([['8.2', 0, 50000, 20000, 30000]], array_map(static fn (array $f): array => [$f['code'], $f['opening_cents'], $f['in_cents'], $f['out_cents'],
            $f['closing_cents']], $october['accounts']), 'Contas a pagar (8.1) fica de fora: é calculada.');
        $sheetOct = array_column((new BalanceSheet($this->pdo, $scope))->build($user, 1, '2025-10-31')['liabilities']['rows'], 'amount_cents', 'code');
        self::assertSame($sheetOct['8.2'], $october['accounts'][0]['closing_cents']);
        $december = array_column(Accountability::funds($accounting->ledger($user, 1, '2025-12-01', '2025-12-31', 'competence', '8'))['accounts'], null, 'code');
        self::assertSame([$liabilities['8.2'], $liabilities['8.3']], [$december['8.2']['closing_cents'], $december['8.3']['closing_cents']]);
        self::assertSame([7000 + 5000, 7000], [$december['8.3']['in_cents'], $december['8.3']['out_cents']], 'O lançamento errado entra e sai (estorno).');
        self::assertCount(3, $december['8.3']['movements']);

        $next = (new BalanceSheet($this->pdo, $scope))->build($user, 1, '2026-01-15');
        $equityNext = array_column($next['equity']['rows'], 'amount_cents', 'code');
        self::assertSame([0, 100000 + 241020 - 30000, 0], [$equityNext['9.2'], $equityNext['9.1'], $next['diferenca']], 'Virada do ano: o exercício vai para os acumulados.');
    }

    /** ADR 0176: saldo inicial com data dentro do período vira linha do Razão, e o saldo final bate com o Balanço. */
    public function testLedgerOpeningInsideThePeriodMatchesTheBalanceSheet(): void
    {
        $scope = new CondominiumScope($this->pdo);
        $user = $this->user();
        $patrimonial = new PatrimonialEntries($this->pdo, $scope);
        $move = static fn (string $date, int $cents): array => $patrimonial->create($user, ['condominium_id' => 1, 'entry_date' => $date,
            'description' => "Reforço de outros fundos {$date}", 'amount_cents' => $cents, 'debit_account_id' => 82, 'credit_account_id' => 83], self::TODAY);
        $move('2025-03-10', 2000);                       // antes do saldo inicial: histórico substituído
        $move('2025-08-10', 1500);                       // depois: soma
        $this->entries([
            [1, 'receita', 2, 10, 'Taxa fevereiro', 100000, '2025-02', '2025-02-10', '2025-02-10'],
            [2, 'receita', 2, 10, 'Taxa julho', 30000, '2025-07', '2025-07-10', '2025-07-10'],
        ]);
        $openings = new ChartOpenings($this->pdo, $scope);
        $openings->set($user, 1, 83, '2025-06-01', 9000);
        $openings->set($user, 1, 2, '2025-05-01', 50000);

        $sheet = (new BalanceSheet($this->pdo, $scope))->build($user, 1, '2025-12-31');
        $liabilities = array_column($sheet['liabilities']['rows'], 'amount_cents', 'code');
        self::assertSame(9000 + 1500, $liabilities['8.3']);
        foreach (['competence', 'cash'] as $basis) {
            $ledger = (new AccountingReports($this->pdo, $scope))->ledger($user, 1, '2025-01-01', '2025-12-31', $basis);
            $accounts = array_column($ledger['accounts'], null, 'code');
            self::assertSame([[], ['1.01', '8.3']], [$ledger['openings_ignored'], $ledger['openings_in_period']], $basis);
            self::assertSame($liabilities['8.3'], $accounts['8.3']['closing_cents'], "{$basis}: Razão da 8.3 = Balanço.");
            self::assertSame(50000 + 30000, $accounts['1.01']['closing_cents'], "{$basis}: saldo inicial + o que vem depois.");
            $line = array_values(array_filter($accounts['1.01']['lines'], static fn (array $l): bool => $l['source'] === 'opening'))[0];
            self::assertSame(['2025-05-01', 50000, 0], [$line['on_date'], $line['debit_cents'], $line['credit_cents']], "{$basis}: receita que baixa vai a débito.");
            $fund = array_values(array_filter($accounts['8.3']['lines'], static fn (array $l): bool => $l['source'] === 'opening'))[0];
            self::assertSame([0, 7000, 9000], [$fund['debit_cents'], $fund['credit_cents'], $fund['balance_cents']], "{$basis}: o ajuste leva a conta ao saldo informado.");
            $a = $accounts['8.3'];
            self::assertSame($a['opening_cents'] + $a['credit_cents'] - $a['debit_cents'], $a['closing_cents'], "{$basis}: débitos e créditos fecham.");
        }
    }

    public function testReviewFixes(): void
    {
        $scope = new CondominiumScope($this->pdo);
        // A1: o filtro da auditoria não usa ESCAPE '\' (no MySQL a barra escapa a aspa e a consulta quebra).
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/AdminReports.php');
        self::assertStringNotContainsString("ESCAPE '\\\\'", $sql);
        self::assertStringContainsString("LIKE 'erp!_%' ESCAPE '!'", $sql);

        // A2: gravação simultânea do mesmo saldo inicial vira atualização. A outra gravação entra entre a leitura e o INSERT
        // (no SQLite um gatilho não serve: a falha do comando desfaz também o que o gatilho gravou).
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RaceStatement::class]);
        RaceStatement::arm($this->pdo, 'INSERT INTO erp_chart_account_openings',
            "INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (1, 82, '2025-01-01', 1)");
        $list = (new ChartOpenings($this->pdo, $scope))->set($this->user(), 1, 82, '2025-02-01', 12345);
        self::assertTrue(RaceStatement::fired());
        self::assertSame([[1, 82, '2025-02-01', 12345]], $this->pdo->query('SELECT condominium_id, chart_account_id, opened_on, amount_cents FROM erp_chart_account_openings')->fetchAll(PDO::FETCH_NUM));
        self::assertContains(12345, array_column($list['rows'], 'amount_cents'));

        // A3: convite simultâneo ao mesmo condomínio não dá erro 500 (a chave repetida conta como "já tinha").
        $this->pdo->exec("INSERT INTO access_roles (id, name, slug, level) VALUES (3, 'Admin', 'client_admin', 70), (5, 'Operador', 'operator', 30)");
        $this->pdo->exec("INSERT INTO access_permissions (id, name, slug, group_name) VALUES (1, 'ERP', 'erp.access', 'ERP')");
        $this->pdo->exec('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (5, 1)');
        $this->pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (7, 3), (10, 5)');
        RaceStatement::arm($this->pdo, 'INSERT INTO user_condominium_access',
            'INSERT INTO user_condominium_access (user_id, condominium_id, granted_by) VALUES (10, 1, NULL)');
        $invite = (new ErpAccess($this->pdo, $scope, static fn (): bool => true))->invite($this->user(), 'ops@x.test', [1]);
        self::assertTrue(RaceStatement::fired());
        self::assertSame([[], [1]], [$invite['granted'], $invite['already']]);
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);

        // A4: outra violação de restrição na prévia da importação não entra em laço; volta como erro.
        $this->pdo->exec('DROP TABLE erp_patrimonial_imports');
        $this->pdo->exec("CREATE TABLE erp_patrimonial_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER, idempotency_key TEXT, sha256 TEXT,
            original_name TEXT CHECK (length(original_name) > 200), content BLOB, status TEXT DEFAULT 'previewed', rows_count INTEGER, error_count INTEGER, summary_json TEXT,
            created_by INTEGER, applied_by INTEGER, applied_at TEXT)");
        try {
            (new PatrimonialImports($this->pdo, $scope, true))->preview($this->user(), ['condominium_id' => 1],
                $this->csv("data;descricao;valor;conta_debito;conta_credito\n15/12/2025;Fundo;1,00;9.2;8.2\n"), self::TODAY);
            self::fail('Gravou com a restrição violada.');
        } catch (\PDOException $error) {
            self::assertSame(19, $error->errorInfo[1]);
        }

        // A5: o balanço soma os movimentos patrimoniais no banco (GROUP BY), sem trazer lançamento por lançamento.
        self::assertStringContainsString('GROUP BY a.code, a.nature, m.entry_date', (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/PatrimonialEntries.php'));
    }

    /** @param list<array{0:int,1:string,2:int,3:?int,4:string,5:int,6:string,7:string,8:?string}> $rows */
    private function entries(array $rows): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (id, condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }
    }

    /** @return array{name:string, tmp_name:string, error:int} */
    private function csv(string $content): array
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'coh');
        file_put_contents($file, $content);
        $this->files[] = $file;
        return ['name' => 'lote.csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK];
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}

/** Simula a outra requisição: grava a linha concorrente logo antes do INSERT do serviço (uma vez). */
class RaceStatement extends \PDOStatement
{
    private static ?PDO $pdo = null;
    private static string $match = '';
    private static string $sql = '';
    private static bool $fired = false;

    protected function __construct()
    {
    }

    public static function arm(PDO $pdo, string $match, string $sql): void
    {
        [self::$pdo, self::$match, self::$sql, self::$fired] = [$pdo, $match, $sql, false];
    }

    public static function fired(): bool
    {
        return self::$fired;
    }

    public function execute(?array $params = null): bool
    {
        if (!self::$fired && self::$pdo !== null && str_starts_with(ltrim($this->queryString), self::$match)) {
            self::$fired = true;
            self::$pdo->exec(self::$sql);
        }
        return parent::execute($params);
    }
}
