<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeInterHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Services\Erp\Banking\BankStatementFetch;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;
use Source\Services\Erp\Imports\OfxParser;

/** Extrato e saldo pela API do Inter (ADR 0318), com transporte falso: nada sai da máquina. */
final class BankStatementFetchTest extends TestCase
{
    private FakeInterHttp $http;
    private InterBankAdapter $adapter;

    protected function setUp(): void
    {
        $this->http = new FakeInterHttp();
        $this->adapter = new InterBankAdapter(['client_id' => 'cid-teste', 'client_secret' => 'segredo-teste', 'certificate' => 'CERT', 'private_key' => 'KEY',
            'account_number' => '1234567-8'], $this->http);
    }

    private static function tx(string $id, string $on, string $op, string|float $value, string $title = 'Pix recebido', string $desc = 'Pessoa Teste'): array
    {
        return ['idTransacao' => $id, 'dataInclusao' => $on . ' 10:00:00', 'dataTransacao' => $on, 'tipoTransacao' => 'PIX', 'tipoOperacao' => $op,
            'valor' => $value, 'titulo' => $title, 'descricao' => $desc];
    }

    public function testStatementMapsCreditsDebitsAndPagesThroughEverything(): void
    {
        for ($i = 1; $i <= 230; $i++) {
            $this->http->statement[] = self::tx('T' . $i, '2026-11-' . str_pad((string)(1 + $i % 15), 2, '0', STR_PAD_LEFT), $i % 3 === 0 ? 'D' : 'C', $i % 3 === 0 ? '12.5' : 500.0);
        }
        $lines = $this->adapter->statement('2026-11-01', '2026-11-16');
        self::assertCount(230, $lines, 'Todas as páginas do extrato.');
        self::assertSame(3, $this->http->count('GET', '/banking/v2/extrato/completo'), '100 por página.');
        self::assertSame(['fitid' => 'inter-T1', 'kind' => 'credit', 'posted_on' => '2026-11-02', 'amount_cents' => 50000, 'memo' => 'Pix recebido - Pessoa Teste'], $lines[0]);
        self::assertSame(['debit', -1250], [$lines[2]['kind'], $lines[2]['amount_cents']]);
        self::assertSame('12345678', $this->http->requests[1]['headers']['x-conta-corrente'], 'Conta corrente no cabeçalho.');
    }

    public function testStatementUsesItsOwnScopeAndKeepsChargesWorking(): void
    {
        $this->http->refuseStatementScope = true;
        try {
            $this->adapter->statement('2026-11-01', '2026-11-16');
            self::fail('Sem o escopo do extrato, o Inter recusa.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('consultar extrato e saldo', $error->getMessage());
        }
        $this->adapter->checkAuthentication();
        self::assertSame(['extrato.read', InterBankAdapter::SCOPES], $this->http->tokenScopes, 'Token da cobrança pedido à parte, sem o escopo do extrato.');
    }

    public function testRateLimitWaitsAndRetriesThenGivesUpClearly(): void
    {
        $waits = [];
        $adapter = new InterBankAdapter(['client_id' => 'cid-teste', 'client_secret' => 'segredo-teste', 'certificate' => 'CERT', 'private_key' => 'KEY'],
            $this->http, InterBankAdapter::SANDBOX_URL, null, function (int $seconds) use (&$waits): void {
                $waits[] = $seconds;
            });
        $this->http->statement = [self::tx('T1', '2026-11-03', 'C', '10.00')];
        $this->http->rateLimited = 2;
        self::assertCount(1, $adapter->statement('2026-11-01', '2026-11-16'), 'Depois de esperar, a consulta passa.');
        self::assertSame([5, 15], $waits);
        $this->http->rateLimited = 10;
        try {
            $adapter->balance('2026-11-16');
            self::fail('Limite que não passa vira erro claro.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ao buscar o saldo', $error->getMessage());
            self::assertStringContainsString('Resposta do banco: Inter recusou (429)', $error->getMessage());
        }
        self::assertSame([5, 15, 5, 15, 30], $waits);
    }

    public function testBalanceAndLineFallbacks(): void
    {
        self::assertSame(150025, $this->adapter->balance('2026-11-16'));
        $this->http->balance = '-20.5';
        self::assertSame(-2050, $this->adapter->balance('2026-11-16'));
        $this->http->balance = null;
        self::assertNull($this->adapter->balance('2026-11-16'));

        $seen = [];
        $a = InterBankAdapter::statementLine(['dataTransacao' => '2026-11-03', 'tipoOperacao' => 'D', 'valor' => '30', 'titulo' => 'Tarifa'], $seen);
        $b = InterBankAdapter::statementLine(['dataTransacao' => '2026-11-03', 'tipoOperacao' => 'D', 'valor' => '30', 'titulo' => 'Tarifa'], $seen);
        self::assertStringStartsWith('inter-h', $a['fitid'], 'Sem idTransacao, o FITID vem do conteúdo.');
        self::assertSame($a['fitid'] . '-2', $b['fitid'], 'Duas tarifas iguais no mesmo dia são duas linhas.');
        self::assertNull(InterBankAdapter::statementLine(['dataTransacao' => '2026-11-03', 'tipoOperacao' => 'X', 'valor' => '30'], $seen));
        self::assertNull(InterBankAdapter::statementLine(['dataTransacao' => 'ontem', 'tipoOperacao' => 'C', 'valor' => '30'], $seen));
    }

    public function testPeriodRules(): void
    {
        self::assertSame(['2026-08-19', '2026-11-16'], BankStatementFetch::period('2026-08-19', '2026-11-16', '2026-11-16'), '90 dias contando os dois.');
        foreach ([['2026-08-18', '2026-11-16'], ['2026-11-10', '2026-11-01'], ['2026-11-01', '2026-11-17'], ['', '2026-11-01'], ['2026-02-30', '2026-03-01']] as [$from, $to]) {
            try {
                BankStatementFetch::period($from, $to, '2026-11-16');
                self::fail("Período {$from} a {$to} deveria ser recusado.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOfxRoundTripKeepsLinesBalanceAndAccount(): void
    {
        $lines = [
            ['fitid' => 'inter-A1', 'kind' => 'credit', 'posted_on' => '2026-11-03', 'amount_cents' => 50000, 'memo' => 'Pix recebido - Ana & <Cia>'],
            ['fitid' => 'inter-A2', 'kind' => 'debit', 'posted_on' => '2026-11-04', 'amount_cents' => -1205, 'memo' => "Tarifa\nboleto"],
        ];
        $ofx = OfxParser::parse(BankStatementFetch::toOfx('077', '1234567-8', '2026-11-01', '2026-11-16', $lines, -99, '20261116120000'));
        self::assertSame('1234567-8', $ofx['account_id']);
        self::assertSame(['2026-11-01', '2026-11-16', -99, '2026-11-16'], [$ofx['from'], $ofx['to'], $ofx['ledger_balance_cents'], $ofx['ledger_as_of']]);
        self::assertSame('Pix recebido - Ana & <Cia>', $ofx['transactions'][0]['memo']);
        self::assertSame('Tarifa boleto', $ofx['transactions'][1]['memo']);
        self::assertSame(array_column($lines, 'amount_cents'), array_column($ofx['transactions'], 'amount_cents'));
        self::assertSame(array_column($lines, 'fitid'), array_column($ofx['transactions'], 'fitid'));
        self::assertNull(OfxParser::parse(BankStatementFetch::toOfx('077', '1', '2026-11-01', '2026-11-02', $lines, null, 'x'))['ledger_balance_cents']);
    }
}
