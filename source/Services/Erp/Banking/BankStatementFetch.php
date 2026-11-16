<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Imports\OfxParser;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\AppLogger;

/**
 * Extrato e saldo buscados no banco pela API (ADR 0318, primeiro banco: Inter). O sistema monta, com o
 * que o banco devolve, um OFX igual ao que a pessoa enviaria à mão e passa pela mesma prévia de
 * Importações > Extrato (AP-140): linhas que já existem ficam como "Já importada" (FITID = id da
 * transação no banco), a conciliação procura o lançamento pago ou a transferência e o saldo do banco
 * é comparado com o do sistema. Nada é gravado antes de aplicar, e nada movimenta dinheiro.
 */
final class BankStatementFetch
{
    private PDO $pdo;
    private CondominiumScope $scope;
    private Imports $imports;
    private \Closure $today;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?Imports $imports = null, ?\Closure $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->imports = $imports ?? new Imports($this->pdo, $this->scope);
        $this->today = $today ?? static fn (): string => date('Y-m-d');
    }

    /**
     * Contas ativas do condomínio que buscam o extrato pelo banco (adaptador com extrato e credencial no cofre).
     *
     * @return list<array{id:int, label:string, adapter:string}>
     */
    public function accounts(User $user, int $condominiumId): array
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT b.id, b.name, b.account_number, b.adapter FROM erp_bank_accounts b JOIN erp_bank_credentials c ON c.bank_account_id = b.id
            WHERE b.condominium_id = :c AND b.deleted_at IS NULL AND b.status = 'active' AND b.adapter IN ('inter','cora') ORDER BY b.name, b.id");
        $stmt->execute(['c' => $condominiumId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => $r['name'] . ($r['account_number'] ? ' · ' . $r['account_number'] : ''),
            'adapter' => (string)$r['adapter']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Busca o extrato de $input['from'] a $input['to'] (no máximo 90 dias, até hoje) e o saldo do fim do período,
     * e devolve a prévia da importação (mesmo formato de POST /imports/preview).
     */
    public function preview(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null || !$this->scope->canAccess($user, (int)$condominium)) {
            throw new RegistryNotFound();
        }
        [$from, $to] = self::period($input['from'] ?? null, $input['to'] ?? null, ($this->today)());
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => (int)filter_var($input['bank_account_id'] ?? 0, FILTER_VALIDATE_INT), 'c' => (int)$condominium]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new InvalidArgumentException('Escolha uma conta bancária ativa deste condomínio.');
        }
        try {
            $adapter = BankAdapters::forAccount($account, $this->pdo);
        } catch (RuntimeException | InvalidArgumentException) {
            $adapter = null;
        }
        if (!$adapter instanceof BankStatementSource) {
            throw new InvalidArgumentException('Esta conta não busca o extrato pelo banco. Conecte a conta em Configurações > Integrações ou envie o arquivo OFX.');
        }
        try {
            $lines = $adapter->statement($from, $to);
            try {
                $balance = $adapter->balance($to);
            } catch (RuntimeException $error) {
                // Saldo é só para a conferência: sem ele, a prévia sai sem comparar o saldo (e o motivo fica no log).
                $balance = null;
                AppLogger::log('notice', 'Saldo do banco não veio', ['event_type' => 'bank_balance_fetch_failed', 'bank_account_id' => (int)$account['id'],
                    'error' => mb_substr($error->getMessage(), 0, 255)], 'erp');
            }
        } catch (InvalidArgumentException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            AppLogger::log('warning', 'Extrato do banco não veio', ['event_type' => 'bank_statement_fetch_failed', 'bank_account_id' => (int)$account['id'],
                'adapter' => $adapter instanceof BankAdapter ? $adapter->name() : '', 'error' => mb_substr($error->getMessage(), 0, 255)], 'erp');
            throw new \DomainException(mb_substr($error->getMessage(), 0, 400));
        }
        // Linha que o leitor de OFX recusaria (valor acima do teto, data estranha) fica de fora sem travar o resto
        // (o sandbox do Inter mandou uma assim em 16/11/2026); quantas foram puladas volta na prévia e no log.
        $skipped = [];
        $lines = array_values(array_filter($lines, static function (array $line) use (&$skipped): bool {
            if (self::readable($line)) {
                return true;
            }
            $skipped[] = ['posted_on' => (string)($line['posted_on'] ?? ''), 'amount_cents' => $line['amount_cents'] ?? null];
            return false;
        }));
        if ($skipped !== []) {
            AppLogger::log('notice', 'Linhas do extrato do banco puladas', ['event_type' => 'bank_statement_lines_skipped', 'bank_account_id' => (int)$account['id'],
                'count' => count($skipped), 'sample' => array_slice($skipped, 0, 5)], 'erp');
        }
        if ($lines === []) {
            throw new \DomainException('O banco não tem lançamentos nesta conta entre ' . date('d/m/Y', (int)strtotime($from)) . ' e ' . date('d/m/Y', (int)strtotime($to)) . '.');
        }
        $name = sprintf('extrato-%s-%s-a-%s.ofx', $adapter instanceof BankAdapter ? $adapter->name() : 'banco', $from, $to);
        $ofx = self::toOfx((string)($account['bank_code'] ?? ''), (string)$account['account_number'], $from, $to, $lines, $balance, (new \DateTimeImmutable())->format('YmdHis.u'));
        return $this->imports->previewFetchedStatement($user, ['condominium_id' => (int)$condominium, 'bank_account_id' => (int)$account['id']], $ofx, $name)
            + ['skipped_bank_lines' => count($skipped)];
    }

    /** A linha passa nas mesmas regras do leitor de OFX (FITID, data e valor até 10 dígitos inteiros)? Regra pura. */
    public static function readable(array $line): bool
    {
        if (!is_string($line['fitid'] ?? null) || trim($line['fitid']) === '' || !is_string($line['posted_on'] ?? null) || !is_int($line['amount_cents'] ?? null)) {
            return false;
        }
        try {
            $parsed = OfxParser::parse(self::toOfx('', '1', $line['posted_on'], $line['posted_on'], [$line + ['memo' => '']], null, 'x'));
        } catch (\InvalidArgumentException) {
            return false;
        }
        return ($parsed['transactions'][0]['amount_cents'] ?? null) === $line['amount_cents'];
    }

    /**
     * Período válido: datas AAAA-MM-DD, início ≤ fim ≤ hoje, até 90 dias (regra pura).
     *
     * @return array{0:string, 1:string}
     */
    public static function period(mixed $from, mixed $to, string $today): array
    {
        $parse = static function (mixed $value): ?\DateTimeImmutable {
            $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };
        $start = $parse($from);
        $end = $parse($to);
        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Informe o início e o fim do período do extrato.');
        }
        if ($start > $end) {
            throw new InvalidArgumentException('O início do período precisa ser antes do fim.');
        }
        if ($end->format('Y-m-d') > $today) {
            throw new InvalidArgumentException('O fim do período não pode ser depois de hoje.');
        }
        if ((int)$start->diff($end)->days + 1 > BankStatementSource::MAX_DAYS) {
            throw new InvalidArgumentException('O banco entrega no máximo ' . BankStatementSource::MAX_DAYS . ' dias por vez: escolha um período menor.');
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * OFX 1 (SGML) com as linhas e o saldo, no formato que o OfxParser lê (regra pura). $stamp entra no
     * cabeçalho: a mesma busca feita de novo vira outra prévia, e as linhas repetidas ficam "Já importada".
     *
     * @param list<array{fitid:string, kind:string, posted_on:string, amount_cents:int, memo:string}> $lines
     */
    public static function toOfx(string $bankCode, string $accountNumber, string $from, string $to, array $lines, ?int $balanceCents, string $stamp): string
    {
        $e = static fn (string $v): string => htmlspecialchars(str_replace(["\r", "\n"], ' ', $v), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $d = static fn (string $iso): string => str_replace('-', '', $iso);
        $money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
        $out = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nENCODING:UTF-8\n\n<OFX>\n<SIGNONMSGSRSV1><SONRS><DTSERVER>" . $e($stamp) . "</SONRS></SIGNONMSGSRSV1>\n"
            . "<BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>BRL\n<BANKACCTFROM><BANKID>" . $e($bankCode) . "\n<ACCTID>" . $e($accountNumber) . "\n</BANKACCTFROM>\n"
            . '<BANKTRANLIST><DTSTART>' . $d($from) . "\n<DTEND>" . $d($to) . "\n";
        foreach ($lines as $line) {
            $out .= '<STMTTRN><TRNTYPE>' . ($line['amount_cents'] < 0 ? 'DEBIT' : 'CREDIT') . "\n<DTPOSTED>" . $d($line['posted_on']) . "\n<TRNAMT>" . $money($line['amount_cents'])
                . "\n<FITID>" . $e($line['fitid']) . "\n<MEMO>" . $e($line['memo']) . "\n</STMTTRN>\n";
        }
        $out .= "</BANKTRANLIST>\n";
        if ($balanceCents !== null) {
            $out .= '<LEDGERBAL><BALAMT>' . $money($balanceCents) . "\n<DTASOF>" . $d($to) . "\n</LEDGERBAL>\n";
        }
        return $out . "</STMTRS></STMTTRNRS></BANKMSGSRSV1>\n</OFX>\n";
    }
}
