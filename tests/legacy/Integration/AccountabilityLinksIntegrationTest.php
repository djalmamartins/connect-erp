<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AccountabilityDeadline;
use Source\Services\Erp\AccountabilityLinks;
use Source\Services\Erp\CashBook;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;

/** Link público da prestação de contas, livro anual e prazo do 5º dia útil (ADR 0300), no MariaDB. */
final class AccountabilityLinksIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private EntryAttachments $attachments;
    private VirtualDisk $disk;
    private AccountabilityLinks $links;
    private string $root;
    private string $work;
    private int $fee;
    private int $water;
    private int $bank;
    private int $copasa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Residencial Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->root = sys_get_temp_dir() . '/al-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/al-work-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0700, true);
        $this->disk = new VirtualDisk($this->pdo, null, $this->root, true);
        $this->attachments = new EntryAttachments($this->pdo, null, $this->disk);
        $this->links = new AccountabilityLinks($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água e esgoto'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => '1234-5', 'kind' => 'corrente', 'opening_balance_cents' => '1.000,00', 'opened_on' => '2025-01-01'])['id'];
        $this->pdo->exec("INSERT INTO erp_suppliers (legal_name, document, category, status) VALUES ('Copasa', '17281106000103', 'servicos', 'active')");
        $this->copasa = (int)$this->pdo->lastInsertId();
        foreach (['101', '102'] as $number) {
            $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type, status) VALUES (?, ?, 'apartamento', 'active')")->execute([$this->alpha, $number]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->work] as $dir) {
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function testMonthlyLinkGroupsByCategoryAndSupplierWithDocumentsInOrder(): void
    {
        $units = array_map('intval', $this->pdo->query('SELECT id FROM erp_units WHERE condominium_id = ' . $this->alpha . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($units as $unit) {
            $this->entry('receita', $this->fee, '500,00', '2026-10-05', 'Taxa outubro', ['unit_id' => $unit]);
        }
        $water = $this->entry('despesa', $this->water, '320,00', '2026-10-12', 'Conta de água setembro', ['supplier_id' => $this->copasa, 'document_number' => 'NF 778']);
        $other = $this->entry('despesa', $this->water, '15,00', '2026-10-20', 'Taxa de religação', []);
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        // Enviados fora de ordem: no link saem comprovante, conta, nota fiscal.
        $nota = $this->attachments->attach($this->admin, $water, $this->file('NF 778.pdf', $pdf . 'nf'), 'nota_fiscal');
        $proof = $this->attachments->attach($this->admin, $water, $this->file('comprovante.pdf', $pdf . 'pg'), 'comprovante');
        $bill = $this->attachments->attach($this->admin, $water, $this->file('fatura copasa.pdf', $pdf . 'ft'), 'conta');
        self::assertSame(['conta', 'Conta ou fatura'], [$bill['kind'], $bill['kind_label']]);

        try {
            $this->links->generate($this->admin, $this->alpha, 'monthly', '2026-10');
            self::fail('Gerou com o mês aberto.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Feche o mês 2026-10', $error->getMessage());
        }

        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-10');
        $done = $this->links->afterClose($this->admin, $this->alpha, '2026-10');
        self::assertSame([], $done['errors']);
        $link = $done['monthly'];
        self::assertSame([true, 1, 'active', 2], [$link['created'], $link['version'], $link['status'], $link['notified_units']]);
        self::assertNull($done['annual'], 'Fora de dezembro não sai o livro anual.');
        self::assertStringContainsString('foi para a fila de e-mails de 2 unidades', AccountabilityLinks::closeMessage($done));
        self::assertMatchesRegularExpression('~/prestacao/[a-f0-9]{32}$~', $link['url']);
        $token = substr($link['url'], -32);

        $outbox = $this->pdo->query("SELECT condominium_id, payload_json FROM erp_notification_outbox WHERE event_type = 'accountability.published' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $outbox, 'Um aviso por unidade.');
        self::assertSame([$this->alpha], array_values(array_unique(array_map('intval', array_column($outbox, 'condominium_id')))), 'Só o condomínio do link.');
        self::assertStringNotContainsString($token, implode('', array_column($outbox, 'payload_json')), 'O token não fica na fila.');
        self::assertSame(['units' => 0, 'version' => 1, 'already_sent' => true], $this->links->notifyResidents($this->admin, $link['id']), 'A mesma versão não sai duas vezes.');

        $view = $this->links->publicView($token);
        self::assertSame(['monthly', '2026-10', 1, false], [$view['kind'], $view['period'], $view['version'], $view['under_review']]);
        self::assertSame('Residencial Alpha', $view['condominium']['name']);
        [$revenue, $expense] = $view['book']['sections'];
        self::assertSame([100000, 33500], [$revenue['total_cents'], $expense['total_cents']]);
        self::assertSame(['Unidades do condomínio', 'Recebido de 2 unidades', []], [$revenue['categories'][0]['groups'][0]['name'],
            $revenue['categories'][0]['groups'][0]['summary'], $revenue['categories'][0]['groups'][0]['items']]);
        $water = $expense['categories'][0];
        self::assertSame(['2.01', 'Água e esgoto', 33500], [$water['code'], $water['name'], $water['total_cents']]);
        self::assertSame(['Copasa', 'Sem fornecedor informado'], array_column($water['groups'], 'name'));
        $copasa = $water['groups'][0]['items'][0];
        self::assertSame(['comprovante', 'conta', 'nota_fiscal'], array_column($copasa['documents'], 'kind'), 'Pagamento, conta e nota fiscal em sequência.');
        self::assertSame(['NF 778', true], [$copasa['document_number'], $copasa['proof']]);
        self::assertSame([], $water['groups'][1]['items'][0]['documents']);
        self::assertSame([3, 1], [$view['book']['documents'], $view['book']['missing_proof']]);
        $json = (string)$this->pdo->query('SELECT payload_json FROM erp_accountability_links WHERE id = ' . $link['id'])->fetchColumn();
        self::assertStringNotContainsString('Unidade 101', $json, 'Recebimento de unidade sai só somado.');
        self::assertStringNotContainsString('"path"', $json, 'O caminho do arquivo nunca vai no link.');
        self::assertSame(1, (int)$this->pdo->query('SELECT view_count FROM erp_accountability_links WHERE id = ' . $link['id'])->fetchColumn());

        $file = $this->links->publicFile($token, $bill['id'], $this->disk);
        self::assertSame(['application/pdf', 'fatura copasa.pdf'], [$file['mime'], $file['name']]);
        self::assertStringEndsWith('ft', (string)file_get_contents($file['path']));
        self::assertNull($this->links->publicFile(str_repeat('0', 32), $bill['id'], $this->disk), 'Outro token não baixa.');
        $late = $this->attachments->attach($this->admin, $other, $this->file('recibo.pdf', $pdf . 'rc'), 'recibo');
        self::assertNull($this->links->publicFile($token, $late['id'], $this->disk), 'Documento fora do livro congelado não baixa.');
        self::assertNull($this->links->publicView('nao-e-token'));

        // Gerar de novo com o anexo novo muda o conteúdo: versão 2, mesmo endereço.
        $again = $this->links->generate($this->admin, $this->alpha, 'monthly', '2026-10');
        self::assertSame([false, true, 2, $link['url']], [$again['created'], $again['changed'], $again['version'], $again['url']]);
        self::assertNotNull($this->links->publicFile($token, $late['id'], $this->disk));
        $same = $this->links->generate($this->admin, $this->alpha, 'monthly', '2026-10');
        self::assertSame([false, false, 2], [$same['created'], $same['changed'], $same['version']]);
        self::assertSame(2, $this->links->notifyResidents($this->admin, $link['id'])['units'], 'A versão corrigida pode ser enviada de novo.');

        // Reaberto para correção: o link avisa e continua mostrando a última versão.
        (new MonthClosing($this->pdo))->reopen($this->admin, $this->alpha, '2026-10', 'Corrigir a conta de água');
        self::assertTrue($this->links->publicView($token)['under_review']);

        // Alteração direta no banco: o link não mostra.
        $this->pdo->exec("UPDATE erp_accountability_links SET payload_json = REPLACE(payload_json, '32000', '1') WHERE id = {$link['id']}");
        self::assertNull($this->links->publicView($token));
        $this->pdo->exec("UPDATE erp_accountability_links SET payload_json = REPLACE(payload_json, ':1,', ':32000,') WHERE id = {$link['id']}");

        // Revogar desliga o endereço; gerar de novo cria outro.
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-10');
        $revoked = $this->links->revoke($this->admin, $link['id'], 'Enviado ao grupo errado');
        self::assertSame(['revoked', null], [$revoked['status'], $revoked['url']]);
        self::assertNull($this->links->publicView($token));
        self::assertNull($this->links->publicFile($token, $bill['id'], $this->disk));
        $fresh = $this->links->generate($this->admin, $this->alpha, 'monthly', '2026-10');
        self::assertNotSame($link['url'], $fresh['url'], 'Link revogado não volta.');
        self::assertNotNull($this->links->publicView(substr($fresh['url'], -32)));
    }

    public function testScopeValidationAndAnnualBook(): void
    {
        $outsider = $this->user([$this->beta]);
        $this->expectExceptionSafely(fn () => $this->links->generate($outsider, $this->alpha, 'monthly', '2026-10'), CondominiumAccessDenied::class);
        $this->expectExceptionSafely(fn () => $this->links->generate($this->admin, $this->alpha, 'semanal', '2026-10'), InvalidArgumentException::class);
        $this->expectExceptionSafely(fn () => $this->links->generate($this->admin, $this->alpha, 'annual', '26'), InvalidArgumentException::class);
        $this->expectExceptionSafely(fn () => $this->links->list($outsider, $this->alpha), CondominiumAccessDenied::class);

        $this->entry('despesa', $this->water, '100,00', '2025-03-10', 'Água março', ['supplier_id' => $this->copasa], '2025-03');
        $closing = new MonthClosing($this->pdo);
        for ($m = 1; $m <= 11; $m++) {
            $closing->close($this->admin, $this->alpha, sprintf('2025-%02d', $m));
        }
        try {
            $this->links->generate($this->admin, $this->alpha, 'annual', '2025');
            self::fail('Gerou o ano com dezembro aberto.');
        } catch (DomainException $error) {
            self::assertStringContainsString('12/2025', $error->getMessage());
        }
        $closing->close($this->admin, $this->alpha, '2025-12');
        $done = $this->links->afterClose($this->admin, $this->alpha, '2025-12');
        self::assertNotNull($done['monthly']);
        $annual = $done['annual'];
        self::assertSame(['annual', '2025', 1, 2], [$annual['kind'], $annual['period'], $annual['version'], $annual['notified_units']]);
        $view = $this->links->publicView(substr($annual['url'], -32));
        self::assertCount(12, $view['book']['months']);
        self::assertSame([10000, 100000, 90000], [$view['book']['totals']['out_cents'], $view['book']['totals']['opening_cents'], $view['book']['totals']['closing_cents']]);
        self::assertSame([['2.01', 10000]], array_map(static fn (array $c): array => [$c['code'], $c['total_cents']], $view['book']['categories']));
        self::assertSame('Copasa', $view['book']['months'][2]['sections'][1]['categories'][0]['groups'][0]['name']);
        self::assertSame(['monthly', 'annual'], array_column($this->links->list($this->admin, $this->alpha)['rows'], 'kind'), 'Mais novo primeiro: 2025-12 e depois 2025.');
    }

    public function testDeadlineWarnsTheTeamOnceAndSendsClosedMonths(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO notifications_categories (title, uri) VALUES ('Studio', 'studio')");
        $manager = $this->manager([$this->alpha]);
        $run = static fn (string $today, $pdo): array => (new AccountabilityDeadline($pdo, new DateTimeImmutable($today)))->run();

        $waiting = $run('2026-11-04', $this->pdo);
        self::assertSame(['2026-10', '2026-11-09', 0], [$waiting['competence'], $waiting['deadline'], $waiting['alerts']], 'Finados não conta: o 5º dia útil é 09/11.');
        $soon = $run('2026-11-05', $this->pdo);
        self::assertSame(2, $soon['alerts'], 'Os dois condomínios abertos.');
        self::assertSame(0, $run('2026-11-06', $this->pdo)['alerts'], 'A mesma situação não avisa de novo.');
        $notes = $this->pdo->query('SELECT title, body FROM notifications WHERE users_id = ' . $manager)->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $notes, 'Só o gestor que alcança o condomínio Alpha.');
        self::assertSame('Prestação de contas vence em breve', $notes[0]['title']);
        self::assertStringContainsString('09/11/2026', $notes[0]['body']);

        // Alpha fecha sem passar pelo afterClose (fechado antes deste recurso): a rotina gera e envia.
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-10');
        $late = $run('2026-11-10', $this->pdo);
        self::assertSame([1, 1], [$late['sent_now'], $late['alerts']], 'Alpha enviado agora; Beta atrasado.');
        self::assertSame('overdue', $this->pdo->query("SELECT situation FROM erp_accountability_alerts WHERE condominium_id = {$this->beta} ORDER BY id DESC LIMIT 1")->fetchColumn());
        $final = $run('2026-11-11', $this->pdo);
        self::assertSame([1, 0, 0], [$final['sent'], $final['sent_now'], $final['alerts']]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE event_type = 'accountability.published'")->fetchColumn());
    }

    private function expectExceptionSafely(callable $call, string $class): void
    {
        try {
            $call();
            self::fail('Esperava ' . $class);
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
        }
    }

    private function entry(string $nature, int $account, string $amount, string $paidOn, string $description, array $extra, ?string $competence = null): int
    {
        $competence ??= substr($paidOn, 0, 7);
        return $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => $nature, 'chart_account_id' => $account,
            'amount_cents' => $amount, 'competence' => $competence, 'description' => $description, 'due_on' => $paidOn, 'paid_on' => $paidOn,
            'bank_account_id' => $this->bank] + $extra)['id'];
    }

    /** @return array{name:string, tmp_name:string, error:int} */
    private function file(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }

    /** Usuário com erp.closing.manage pelo papel, só no condomínio dado. */
    private function manager(array $condominiums): int
    {
        $id = $this->createUser(['level' => 2, 'status' => 'confirmed']);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        $slug = 'closer_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Fechar mês', 'erp.closing.manage', 'ERP')")->execute();
        $permission = (int)$this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'erp.closing.manage'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        return $id;
    }
}
