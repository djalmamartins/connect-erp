<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Controllers\Web\Connect\AccountabilityLinkPublic;
use Source\Services\Erp\AccountabilityDeadline as Deadline;
use Source\Services\Erp\AccountabilityLinks as Links;
use Source\Services\Erp\CashBook;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;

/** Link público da prestação de contas e prazo do 5º dia útil (ADR 0300): regras puras, rotas, telas e segurança. */
final class AccountabilityLinksTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private function book(): array
    {
        $lines = CashBook::compose(50000, [
            ['nature' => 'receita', 'amount_cents' => 40000, 'account_code' => '1.01', 'account_name' => 'Taxa', 'counterpart' => 'Unidade 101', 'paid_on' => '2026-10-05', 'description' => 'Taxa', 'document_number' => null],
            ['nature' => 'receita', 'amount_cents' => 40000, 'account_code' => '1.01', 'account_name' => 'Taxa', 'counterpart' => 'Unidade 102', 'paid_on' => '2026-10-06', 'description' => 'Taxa', 'document_number' => null],
            ['nature' => 'receita', 'amount_cents' => 40000, 'account_code' => '1.01', 'account_name' => 'Taxa', 'counterpart' => 'Unidade 101', 'paid_on' => '2026-10-07', 'description' => 'Taxa atrasada', 'document_number' => null],
            ['nature' => 'receita', 'amount_cents' => 1200, 'account_code' => '1.09', 'account_name' => 'Rendimentos', 'counterpart' => null, 'paid_on' => '2026-10-31', 'description' => 'Rendimento poupança', 'document_number' => null],
            ['nature' => 'despesa', 'amount_cents' => 32000, 'account_code' => '2.01', 'account_name' => 'Água', 'counterpart' => 'Copasa', 'paid_on' => '2026-10-12', 'description' => 'Água setembro', 'document_number' => 'NF 1',
                'attachments' => [['id' => 7, 'kind' => 'nota_fiscal', 'kind_label' => 'Nota fiscal', 'original_name' => 'nf.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10],
                    ['id' => 8, 'kind' => 'outro', 'kind_label' => 'Outro documento', 'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10],
                    ['id' => 9, 'kind' => 'conta', 'kind_label' => 'Conta ou fatura', 'original_name' => 'fatura.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10],
                    ['id' => 10, 'kind' => 'comprovante', 'kind_label' => 'Comprovante de pagamento', 'original_name' => 'pix.png', 'mime' => 'image/png', 'size_bytes' => 10]]],
            ['nature' => 'despesa', 'amount_cents' => 9000, 'account_code' => '2.02', 'account_name' => 'Energia', 'counterpart' => 'Cemig', 'paid_on' => '2026-10-15', 'description' => 'Luz', 'document_number' => null],
            ['nature' => 'despesa', 'amount_cents' => 3000, 'account_code' => '2.01', 'account_name' => 'Água', 'counterpart' => 'copasa', 'paid_on' => '2026-10-20', 'description' => 'Religação', 'document_number' => null],
        ]);
        return ['competence' => '2026-10', 'from' => '2026-10-01', 'to' => '2026-10-31', 'opening_cents' => 50000, 'in_cents' => $lines['in_cents'], 'out_cents' => $lines['out_cents'],
            'closing_cents' => $lines['closing_cents'], 'banks_closing_cents' => $lines['closing_cents'], 'reconciled' => true,
            'lines' => array_map(static function (array $l): array { unset($l['attachments']); return $l; }, $lines['lines']), 'annexes' => $lines['annexes'],
            'missing_proof' => CashBook::missingProof($lines['lines']),
            'banks' => [['id' => 1, 'name' => 'Movimento', 'opening_cents' => 50000, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 1, 'transfers_in_cents' => 0, 'transfers_out_cents' => 0]]];
    }

    public function testGroupByCategoryAndSupplierWithDocumentsInTheRequestedOrder(): void
    {
        $group = Links::group($this->book());
        [$revenue, $expense] = $group['sections'];
        self::assertSame([121200, 44000, 4, 3], [$revenue['total_cents'], $expense['total_cents'], $revenue['entries'], $expense['entries']]);
        self::assertSame(['1.01', '1.09'], array_column($revenue['categories'], 'code'));
        $units = $revenue['categories'][0]['groups'];
        self::assertCount(1, $units, 'Recebimentos das unidades num grupo só.');
        self::assertSame(['Unidades do condomínio', 120000, 2, 'Recebido de 2 unidades', []], [$units[0]['name'], $units[0]['total_cents'], $units[0]['units'], $units[0]['summary'], $units[0]['items']]);
        self::assertStringNotContainsString('Unidade 10', json_encode($group), 'Sem a unidade no link.');
        self::assertSame('Outras receitas', $revenue['categories'][1]['groups'][0]['name']);

        self::assertSame(['2.01', '2.02'], array_column($expense['categories'], 'code'));
        $water = $expense['categories'][0];
        self::assertSame([35000, 2], [$water['total_cents'], $water['entries']]);
        self::assertCount(1, $water['groups'], 'Copasa e copasa são o mesmo fornecedor.');
        self::assertSame([35000, 2], [$water['groups'][0]['total_cents'], count($water['groups'][0]['items'])]);
        $first = $water['groups'][0]['items'][0];
        self::assertSame(['comprovante', 'conta', 'nota_fiscal', 'outro'], array_column($first['documents'], 'kind'), 'Pagamento, conta, nota fiscal e depois o resto.');
        self::assertSame([10, 9, 7, 8], array_column($first['documents'], 'id'));
        self::assertTrue($first['proof']);
        self::assertFalse($water['groups'][0]['items'][1]['proof']);
        self::assertSame([4, 2], [$group['documents'], $group['missing_proof']]);
        self::assertArrayNotHasKey('path', $first['documents'][0]);
        self::assertSame([10, 9, 7, 8], Links::documentIds(['kind' => 'monthly', 'book' => $group]));
    }

    public function testAnnualSummaryAndPeriods(): void
    {
        $month = Links::group($this->book());
        $other = $month;
        $other['competence'] = '2026-11';
        $other['totals']['opening_cents'] = $month['totals']['closing_cents'];
        $other['totals']['closing_cents'] = 99;
        $year = Links::annual('2026', [$month, $other]);
        self::assertSame([50000, 242400, 88000, 99, true], [$year['totals']['opening_cents'], $year['totals']['in_cents'], $year['totals']['out_cents'],
            $year['totals']['closing_cents'], $year['totals']['reconciled']]);
        self::assertSame(['receita|1.01', 'receita|1.09', 'despesa|2.01', 'despesa|2.02'], array_map(static fn (array $c): string => $c['nature'] . '|' . $c['code'], $year['categories']));
        self::assertSame([240000, 6], [$year['categories'][0]['total_cents'], $year['categories'][0]['entries']]);
        self::assertSame([8, 4], [$year['documents'], $year['missing_proof']]);
        self::assertSame([10, 9, 7, 8], Links::documentIds(['kind' => 'annual', 'book' => $year]));

        self::assertSame('2026-10', Links::period('monthly', '2026-10'));
        self::assertSame('2026', Links::period('annual', 2026));
        foreach ([['monthly', '2026-13'], ['annual', '26'], ['weekly', '2026']] as [$kind, $value]) {
            try {
                Links::period($kind, $value);
                self::fail("Aceitou {$kind} {$value}");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertTrue(Links::validToken(Links::newToken()));
        self::assertNotSame(Links::newToken(), Links::newToken());
        foreach (['', 'abc', str_repeat('G', 32), str_repeat('a', 33), "../" . str_repeat('a', 29), null, 123] as $bad) {
            self::assertFalse(Links::validToken($bad));
        }
    }

    public function testCloseMessage(): void
    {
        self::assertSame('O link da prestação de contas do mês está pronto e foi para a fila de e-mails de 1 unidade. O livro anual está pronto.',
            Links::closeMessage(['monthly' => ['notified_units' => 1], 'annual' => ['notified_units' => 0], 'errors' => []]));
        self::assertSame('Ainda abertos: 01/2026.', Links::closeMessage(['monthly' => null, 'annual' => null, 'errors' => ['Ainda abertos: 01/2026.']]));
    }

    public function testBusinessDaysAndDeadline(): void
    {
        self::assertSame('2026-04-05', Deadline::easter(2026)->format('Y-m-d'));
        self::assertSame('2027-03-28', Deadline::easter(2027)->format('Y-m-d'));
        $h2026 = Deadline::holidays(2026);
        foreach (['2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04', '2026-11-02', '2026-11-20'] as $day) {
            self::assertContains($day, $h2026, "{$day} é feriado ou dia sem banco.");
        }
        self::assertSame('2026-11-09', Deadline::businessDay('2026-11')->format('Y-m-d'), 'Finados (seg 02/11) não conta.');
        self::assertSame('2026-12-07', Deadline::businessDay('2026-12')->format('Y-m-d'));
        self::assertSame('2027-01-08', Deadline::businessDay('2027-01')->format('Y-m-d'), '01/01 é feriado.');
        self::assertSame('2026-02-06', Deadline::businessDay('2026-02')->format('Y-m-d'));
        self::assertSame(1, Deadline::businessDaysUntil(new DateTimeImmutable('2026-02-13'), new DateTimeImmutable('2026-02-18')), 'Carnaval (16 e 17/02) não conta.');
        self::assertSame(2, Deadline::businessDaysUntil(new DateTimeImmutable('2026-11-05'), new DateTimeImmutable('2026-11-09')));
        self::assertSame(0, Deadline::businessDaysUntil(new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-09')));

        $deadline = new DateTimeImmutable('2026-11-09');
        $cases = [['2026-11-03', false, false, 'waiting'], ['2026-11-05', false, false, 'due_soon'], ['2026-11-09', false, false, 'due_soon'],
            ['2026-11-10', false, false, 'overdue'], ['2026-11-10', true, false, 'send'], ['2026-11-04', true, true, 'sent']];
        foreach ($cases as [$today, $closed, $sent, $expected]) {
            self::assertSame($expected, Deadline::situation(new DateTimeImmutable($today), $deadline, $closed, $sent), "{$today}");
        }
        self::assertStringContainsString('venceu em 09/11/2026', Deadline::message('Alpha', '2026-10', $deadline, 'overdue'));
        self::assertStringContainsString('outubro de 2026 do Alpha precisa ir aos moradores até 09/11/2026', Deadline::message('Alpha', '2026-10', $deadline, 'due_soon'));
    }

    public function testNotificationEventAndMailTemplate(): void
    {
        self::assertContains(Links::EVENT, NotificationOutbox::EVENTS);
        self::assertSame(['template' => 'accountability-link', 'subject' => 'Prestação de contas do condomínio', 'audience' => 'notices'], NotificationDispatcher::NOTICES[Links::EVENT],
            'Vai só para quem aceita comunicado da unidade.');
        self::assertSame(['accountability_link_id' => 3, 'unit_id' => 4, 'kind' => 'monthly'], NotificationOutbox::cleanPayload(['accountability_link_id' => 3, 'unit_id' => 4, 'kind' => 'monthly', 'token' => 'x']),
            'O token nunca entraria na fila.');
        $dispatcher = $this->file('source/Services/Erp/NotificationDispatcher.php');
        self::assertStringContainsString("\$link['status'] !== 'active'", $dispatcher, 'Link revogado não é enviado.');
    }

    public function testPublicPageHeadersRoutesAndTemplate(): void
    {
        $headers = AccountabilityLinkPublic::headers('abc123');
        self::assertSame('no-referrer', $headers['Referrer-Policy'], 'O token não vaza pelo referrer.');
        self::assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
        self::assertSame('no-store, private', $headers['Cache-Control']);
        self::assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("script-src 'nonce-abc123'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);

        $index = $this->file('index.php');
        foreach (['$route->get("/prestacao/{token}", "AccountabilityLinkPublic:show");', '$route->get("/prestacao/{token}/documento/{attachment}", "AccountabilityLinkPublic:file");',
            '$route->post("/accountability-links/{id}/send", "AccountabilityLinks:send"', '$route->post("/accountability-links/{id}/revoke", "AccountabilityLinks:revoke"'] as $route) {
            self::assertStringContainsString($route, $index);
        }
        self::assertLessThan(strpos($index, 'if (erp_host()) {'), strpos($index, '"/prestacao/{token}"'), 'O link responde em qualquer host, como a verificação.');

        $template = $this->file('container/erp/connect/v1/pages/public/accountability-link.php');
        self::assertDoesNotMatchRegularExpression('~(src|href)="https?://~', $template, 'Nenhum recurso de fora (a CSP bloquearia).');
        self::assertStringContainsString('<script nonce="<?= $this->e($nonce) ?>">', $template);
        self::assertStringContainsString('rel="noopener noreferrer"', $template);
        self::assertStringNotContainsString('<?= $item[', $template, 'Tudo passa por $this->e().');
        self::assertStringContainsString('--gold: #c5a131', $template, 'Dourado da Connect.');
        self::assertStringContainsString('--green: #0d2e27', $template, 'Verde da barra lateral do ERP.');
    }

    public function testErpScreensRequireCsrfAndClosingPermission(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['"/prestacao-de-contas/livro-caixa/link", "CashBookScreens:generateLink"', '"/prestacao-de-contas/livro-caixa/link/{id}/enviar", "CashBookScreens:sendLink"',
            '"/prestacao-de-contas/livro-caixa/link/{id}/revogar", "CashBookScreens:revokeLink"'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        $controller = $this->file('source/Controllers/Erp/Connect/CashBookScreens.php');
        self::assertStringContainsString("Access::can('erp.closing.manage', \$user)", $controller);
        self::assertSame(1, substr_count($controller, 'csrf_verify($data)'), 'As três ações passam pelo mesmo guarda com CSRF.');
        $page = $this->file('container/erp/connect/v1/pages/reports/cash-book.php');
        self::assertSame(3, substr_count($page, '<?= csrf_input() ?>'), 'Gerar, enviar e desativar levam o CSRF.');
        self::assertStringContainsString('<?php if ($cashCanPublish): ?>', $page, 'Só quem fecha o mês vê os botões.');
        $api = $this->file('source/Controllers/Api/V1/AccountabilityLinks.php');
        self::assertStringContainsString("(\$manage && !Access::can('erp.closing.manage', \$user))", $api);
        foreach (['source/Controllers/Api/V1/MonthClosings.php', 'source/Controllers/Erp/Connect/MonthClosings.php', 'source/Controllers/Api/V1/MonthClosingBatch.php'] as $closing) {
            self::assertMatchesRegularExpression('/AccountabilityLinks\(\);?\)?.*->afterClose\(/s', $this->file($closing), "{$closing} gera o link depois de fechar.");
        }
        $migration = $this->file('database/migrations/20261114_0900_accountability_links.sql');
        self::assertStringContainsString("enum('conta','nota_fiscal','comprovante','recibo','outro')", $migration);
        self::assertStringContainsString('UNIQUE KEY `uq_erp_accountability_links_token` (`token`)', $migration);
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261114_0900_accountability_links.down.sql');
        self::assertStringContainsString('(new AccountabilityDeadline())->run()', $this->file('tools/accountability-deadline.php'));
    }
}
