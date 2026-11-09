<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\CondominiumScope;

/**
 * Contas que podem entrar num recibo (AP-040, ADR 0057 e 0073), só para a tela escolher: contas a
 * receber válidas e pagas da unidade, sem recibo válido. Só leitura; quem decide é
 * UnitDocuments::issue, que confere tudo de novo na emissão. Fora do escopo = RegistryNotFound.
 */
final class ReceiptCandidates
{
    /** Quantas contas pagas a tela mostra, das mais recentes (o recibo cobre até 60). */
    public const LIMIT = 120;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<array{id:int, description:string, competence:string, due_on:string, paid_on:string, amount_cents:int}> */
    public function forUnit(User $user, int $unitId): array
    {
        $stmt = $this->pdo->prepare('SELECT condominium_id FROM erp_units WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $unitId]);
        $condominium = $stmt->fetchColumn();
        if ($condominium === false || !$this->scope->canAccess($user, (int)$condominium)) {
            throw new RegistryNotFound();
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.description, e.competence, e.due_on, e.paid_on, e.amount_cents FROM erp_entries e
            WHERE e.unit_id = :u AND e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM erp_unit_document_entries de JOIN erp_unit_documents d ON d.id = de.document_id
                              WHERE de.entry_id = e.id AND d.status = 'issued')
            ORDER BY e.paid_on DESC, e.id DESC LIMIT " . self::LIMIT);
        $stmt->execute(['u' => $unitId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'description' => (string)$r['description'],
            'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'paid_on' => (string)$r['paid_on'],
            'amount_cents' => (int)$r['amount_cents']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
