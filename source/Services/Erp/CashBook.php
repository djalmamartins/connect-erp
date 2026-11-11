<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Livro caixa mensal (CN-007, ADR 0174), somente leitura e calculado dos lançamentos válidos pagos no mês
 * (regime de caixa: vale a data do pagamento), de um condomínio:
 *
 * - saldo anterior = soma dos saldos das contas bancárias no dia 1 (o mesmo cálculo da prestação de contas,
 *   FinancialReports::statement, com saldo inicial e transferências);
 * - cada pagamento e recebimento do mês, em ordem de data, com o saldo corrido;
 * - saldo final = saldo anterior + entradas − saídas, conferido com a soma das contas bancárias. Diferença
 *   diferente de zero aparece no livro (lançamento pago sem conta bancária), nunca é escondida;
 * - os anexos de cada lançamento (nota fiscal, comprovante, recibo, ADR 0174) numerados A1, A2... na ordem do
 *   livro, e a lista das despesas pagas sem nota nem comprovante;
 * - o resumo por conta do plano e a situação do fechamento mensal (prévia enquanto a competência está aberta).
 *
 * Transferências entre contas do mesmo condomínio não entram nas linhas: somam zero no caixa do condomínio e
 * aparecem no quadro das contas bancárias.
 */
final class CashBook
{
    public const MAX_LINES = 3000;
    /** Tipos de anexo que comprovam uma despesa paga. */
    public const PROOF_KINDS = ['nota_fiscal', 'comprovante', 'recibo'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Primeiro e último dia da competência AAAA-MM (regra pura). */
    public static function period(string $competence): array
    {
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Escolha o mês do livro caixa (AAAA-MM).');
        }
        $start = new \DateTimeImmutable($competence . '-01');
        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    /**
     * Saldo corrido, totais e numeração dos anexos (regra pura). Cada linha recebe in_cents/out_cents,
     * balance_cents e annex_refs ("A1", "A2"...); os anexos saem numerados na ordem do livro.
     *
     * @param list<array{nature:string, amount_cents:int, attachments?:list<array>}> $lines
     * @return array{lines:list<array>, annexes:list<array>, in_cents:int, out_cents:int, closing_cents:int}
     */
    public static function compose(int $openingCents, array $lines): array
    {
        $balance = $openingCents;
        $in = 0;
        $out = 0;
        $annexes = [];
        foreach ($lines as $i => $line) {
            $amount = (int)$line['amount_cents'];
            $isIn = $line['nature'] === 'receita';
            $isIn ? $in += $amount : $out += $amount;
            $balance += $isIn ? $amount : -$amount;
            $refs = [];
            foreach ($line['attachments'] ?? [] as $attachment) {
                $number = count($annexes) + 1;
                $annexes[] = ['number' => $number, 'ref' => 'A' . $number, 'line' => $i + 1] + $attachment;
                $refs[] = 'A' . $number;
            }
            $lines[$i] = ['seq' => $i + 1, 'in_cents' => $isIn ? $amount : 0, 'out_cents' => $isIn ? 0 : $amount,
                'balance_cents' => $balance, 'annex_refs' => $refs] + $line;
        }
        return ['lines' => array_values($lines), 'annexes' => $annexes, 'in_cents' => $in, 'out_cents' => $out, 'closing_cents' => $openingCents + $in - $out];
    }

    /**
     * Despesas pagas sem nota fiscal, comprovante nem recibo (regra pura).
     *
     * @param list<array> $lines linhas já compostas
     * @return list<array{seq:int, paid_on:string, description:string, amount_cents:int}>
     */
    public static function missingProof(array $lines): array
    {
        $missing = [];
        foreach ($lines as $line) {
            if ($line['nature'] !== 'despesa') {
                continue;
            }
            $kinds = array_column($line['attachments'] ?? [], 'kind');
            if (array_intersect($kinds, self::PROOF_KINDS) === []) {
                $missing[] = ['seq' => (int)$line['seq'], 'entry_id' => (int)($line['entry_id'] ?? 0), 'paid_on' => (string)$line['paid_on'],
                    'description' => (string)$line['description'], 'amount_cents' => (int)$line['amount_cents']];
            }
        }
        return $missing;
    }

    /**
     * Livro caixa de um condomínio na competência. $withPaths inclui o caminho de cada anexo na pasta privada
     * (só para montar o PDF e o pacote; nunca na API).
     */
    public function build(User $user, int $condominiumId, string $competence, bool $withPaths = false): array
    {
        [$from, $to] = self::period($competence);
        $this->scope->assertAccess($user, $condominiumId);

        $stmt = $this->pdo->prepare('SELECT id, name, legal_name, document, street, number, complement, district, city, state FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $condominiumId]);
        $condo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $condominiumId, 'name' => ''];

        $statement = (new FinancialReports($this->pdo, $this->scope))->statement($user, $condominiumId, $from, $to);
        $banks = $statement['banks'];
        $opening = array_sum(array_column($banks, 'opening_cents'));

        $stmt = $this->pdo->prepare("SELECT e.id, e.paid_on, e.nature, e.description, e.document_number, e.amount_cents, e.competence, e.due_on,
                a.code AS account_code, a.name AS account_name, b.name AS bank_name, s.legal_name AS supplier_name, u.label AS unit_label
            FROM erp_entries e
            JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id
            LEFT JOIN erp_suppliers s ON s.id = e.supplier_id
            LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.condominium_id = :c AND e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on BETWEEN :f AND :t
            ORDER BY e.paid_on, FIELD(e.nature, 'receita', 'despesa'), e.id
            LIMIT " . (self::MAX_LINES + 1));
        $stmt->execute(['c' => $condominiumId, 'f' => $from, 't' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_LINES) {
            throw new InvalidArgumentException(sprintf('O mês tem mais de %d movimentos: fale com o suporte para dividir o livro.', self::MAX_LINES));
        }
        $attachments = (new EntryAttachments($this->pdo, $this->scope))->forEntries($condominiumId, array_map(static fn (array $r): int => (int)$r['id'], $rows), $withPaths);
        $lines = array_map(static fn (array $r): array => [
            'entry_id' => (int)$r['id'], 'paid_on' => (string)$r['paid_on'], 'nature' => (string)$r['nature'], 'description' => (string)$r['description'],
            'document_number' => $r['document_number'] !== null ? (string)$r['document_number'] : null, 'amount_cents' => (int)$r['amount_cents'],
            'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'account_code' => (string)$r['account_code'],
            'account_name' => (string)$r['account_name'], 'bank_name' => $r['bank_name'] !== null ? (string)$r['bank_name'] : null,
            'counterpart' => $r['supplier_name'] !== null ? (string)$r['supplier_name'] : ($r['unit_label'] !== null ? 'Unidade ' . $r['unit_label'] : null),
            'attachments' => $attachments[(int)$r['id']] ?? [],
        ], $rows);
        $book = self::compose($opening, $lines);

        $byAccount = [];
        foreach ($book['lines'] as $line) {
            $key = $line['nature'] . '|' . $line['account_code'];
            $byAccount[$key] ??= ['nature' => $line['nature'], 'code' => $line['account_code'], 'name' => $line['account_name'], 'entries' => 0, 'total_cents' => 0];
            $byAccount[$key]['entries']++;
            $byAccount[$key]['total_cents'] += $line['amount_cents'];
        }
        uasort($byAccount, static fn (array $a, array $b): int => [$a['nature'] === 'receita' ? 0 : 1, $a['code']] <=> [$b['nature'] === 'receita' ? 0 : 1, $b['code']]);

        $banksClosing = array_sum(array_column($banks, 'closing_cents'));
        $closing = (new Registry\RegistryService($this->pdo, $this->scope))->isClosed($condominiumId, $competence);
        $missing = self::missingProof($book['lines']);
        foreach ($book['lines'] as &$line) {
            unset($line['attachments']);
        }
        unset($line);

        return [
            'condominium' => self::condominium($condo), 'competence' => $competence, 'from' => $from, 'to' => $to,
            'closed' => $closing,
            'opening_cents' => $opening, 'in_cents' => $book['in_cents'], 'out_cents' => $book['out_cents'], 'closing_cents' => $book['closing_cents'],
            'banks_closing_cents' => $banksClosing, 'difference_cents' => $book['closing_cents'] - $banksClosing,
            'reconciled' => $book['closing_cents'] === $banksClosing,
            'lines' => $book['lines'], 'annexes' => $book['annexes'], 'missing_proof' => $missing,
            'accounts' => array_values($byAccount),
            'banks' => array_map(static fn (array $b): array => array_intersect_key($b, array_flip(['id', 'name', 'opening_cents', 'received_cents', 'paid_cents',
                'closing_cents', 'transfers_in_cents', 'transfers_out_cents'])), $banks),
        ];
    }

    /** Nome do arquivo (regra pura): livro-caixa-<condomínio>-<AAAA-MM>.<ext> */
    public static function filename(array $book, string $extension = 'pdf'): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($book['condominium']['name'] ?? ''))), '-'));
        return sprintf('livro-caixa-%s-%s.%s', $slug !== '' ? $slug : 'condominio', $book['competence'], $extension);
    }

    private static function condominium(array $row): array
    {
        $address = trim(implode(', ', array_filter([
            trim((string)($row['street'] ?? '') . ' ' . (string)($row['number'] ?? '')), (string)($row['complement'] ?? ''), (string)($row['district'] ?? ''),
            trim((string)($row['city'] ?? '') . (($row['state'] ?? '') !== '' ? '/' . $row['state'] : '')),
        ], static fn (string $p): bool => trim($p) !== '')));
        $document = preg_replace('/\D/', '', (string)($row['document'] ?? ''));
        return [
            'id' => (int)$row['id'], 'name' => (string)$row['name'], 'legal_name' => ($row['legal_name'] ?? '') !== '' ? (string)$row['legal_name'] : null,
            'document' => strlen((string)$document) === 14 ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split((string)$document)) : null,
            'address' => $address !== '' ? $address : null,
        ];
    }
}
