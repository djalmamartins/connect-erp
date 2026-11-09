<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;

/**
 * Lançamentos que podem conciliar uma linha pendente do extrato (AP-140, ADR 0058 e 0074), só para a
 * tela escolher: pagos na mesma conta bancária, do mesmo sentido e valor, ainda não conciliados, do
 * mais próximo da data da linha ao mais distante. Só leitura; quem decide é Imports::resolveLine, que
 * confere tudo de novo. Fora do escopo = RegistryNotFound.
 */
final class StatementLineCandidates
{
    public const LIMIT = 20;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * @return array{line: array, candidates: list<array{id:int, description:string, paid_on:string, amount_cents:int, unit_label:?string, document_number:?string, days:int}>}
     */
    public function forLine(User $user, int $lineId): array
    {
        $stmt = $this->pdo->prepare('SELECT l.id, l.condominium_id, l.bank_account_id, l.fitid, l.kind, l.posted_on, l.amount_cents, l.memo, l.status, b.name AS bank_account
            FROM erp_bank_statement_lines l JOIN erp_bank_accounts b ON b.id = l.bank_account_id WHERE l.id = :id');
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($line) || !$this->scope->canAccess($user, (int)$line['condominium_id'])) {
            throw new RegistryNotFound();
        }
        $line = ['id' => (int)$line['id'], 'condominium_id' => (int)$line['condominium_id'], 'bank_account_id' => (int)$line['bank_account_id'],
            'bank_account' => (string)$line['bank_account'], 'fitid' => (string)$line['fitid'], 'kind' => (string)$line['kind'],
            'posted_on' => (string)$line['posted_on'], 'amount_cents' => (int)$line['amount_cents'], 'memo' => $line['memo'], 'status' => (string)$line['status']];
        if ($line['status'] !== 'unmatched') {
            return ['line' => $line, 'candidates' => []];
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.description, e.paid_on, e.amount_cents, e.document_number, u.label AS unit_label,
                ABS(DATEDIFF(e.paid_on, :d)) AS days
            FROM erp_entries e LEFT JOIN erp_units u ON u.id = e.unit_id LEFT JOIN erp_bank_statement_lines l ON l.entry_id = e.id
            WHERE e.bank_account_id = :b AND e.paid_on IS NOT NULL AND e.status = 'active' AND e.deleted_at IS NULL
              AND e.nature = :n AND e.amount_cents = :v AND l.id IS NULL
            ORDER BY days, e.paid_on DESC, e.id DESC LIMIT " . self::LIMIT);
        $stmt->execute(['d' => $line['posted_on'], 'b' => $line['bank_account_id'], 'n' => $line['amount_cents'] >= 0 ? 'receita' : 'despesa', 'v' => abs($line['amount_cents'])]);
        $candidates = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'description' => (string)$r['description'], 'paid_on' => (string)$r['paid_on'],
            'amount_cents' => (int)$r['amount_cents'], 'unit_label' => $r['unit_label'], 'document_number' => $r['document_number'], 'days' => (int)$r['days']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['line' => $line, 'candidates' => $candidates];
    }
}
