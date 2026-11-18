<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketError;
use Source\Support\Audit;

/**
 * 2ª via do boleto para o Connect Talk (issue #400 §6, ADR 0386): GET /api/v1/talk/charges?resident_id=&phone=
 *
 * - Exige o morador e o telefone dele: o telefone tem de ser o do cadastro (mesmos 8 últimos dígitos). Morador de outro
 *   condomínio, inativo, fora do período, sem unidade ou com telefone diferente = 404 sem dizer o motivo.
 * - Só proprietário ou responsável financeiro (owner, financial). Inquilino e morador ficam de fora até decisão do Djalma.
 * - Só boletos registrados no banco (erp_bank_charges.status = registered) de contas a receber da unidade ainda em
 *   aberto, vencidos há no máximo 60 dias (mais antigos: o Talk passa para o Financeiro). No máximo 3, pelo vencimento.
 * - Cada consulta vai para a auditoria com o telefone mascarado. Nada é alterado.
 */
final class TalkCharges
{
    public const MAX_ITEMS = 3;
    public const MAX_OVERDUE_DAYS = 60;
    public const RELATIONSHIPS = ['owner', 'financial'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * @return list<array{id:int, condominium:string, unit:string, competence:string, due_on:string, amount_cents:int,
     *   digitable_line:?string, pix_copy_paste:?string, portal_url:string}>
     * @throws TicketError 422 sem morador ou telefone; 404 quando o telefone não confere
     */
    public function open(?User $agent, int $residentId, string $phone, ?string $today = null): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($residentId <= 0) {
            throw new TicketError('resident_id', 'Informe o morador.');
        }
        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw new TicketError('phone', 'Informe o telefone com DDD.');
        }
        $today ??= date('Y-m-d');
        $resident = $this->resident($agent, $residentId, $today);
        $found = $resident !== null && TalkHelpdesk::samePhone((string)$resident['phone'], $digits);
        Audit::record('read', 'erp_bank_charges', null, null, ['origin' => 'talk', 'action' => 'second_copy', 'resident_id' => $residentId,
            'phone' => TalkHelpdesk::maskPhone($digits), 'found' => $found]);
        if (!$found) {
            throw new TicketError('resident_id', 'Morador não encontrado.', 404);
        }
        $from = date('Y-m-d', (int)strtotime($today . ' -' . self::MAX_OVERDUE_DAYS . ' days'));
        $stmt = $this->pdo->prepare("SELECT b.id, b.due_on, b.amount_cents, b.digitable_line, b.pix_copy_paste, e.id AS entry_id, e.competence
            FROM erp_bank_charges b
            JOIN erp_entries e ON e.id = b.entry_id
            WHERE b.status = 'registered' AND b.condominium_id = :c AND b.due_on >= :from
              AND e.condominium_id = :c2 AND e.unit_id = :u AND e.nature = 'receita' AND e.status = 'active'
              AND e.deleted_at IS NULL AND e.paid_on IS NULL
            ORDER BY b.due_on, b.id LIMIT " . self::MAX_ITEMS);
        $stmt->execute(['c' => $resident['condominium_id'], 'c2' => $resident['condominium_id'], 'u' => $resident['unit_id'], 'from' => $from]);
        $base = function_exists('app_url') ? rtrim((string)app_url('/boletos'), '/') : '/boletos';
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'condominium' => (string)$resident['condominium_name'],
                'unit' => (string)$resident['unit'],
                'competence' => (string)$row['competence'],
                'due_on' => (string)$row['due_on'],
                'amount_cents' => (int)$row['amount_cents'],
                'digitable_line' => $row['digitable_line'] !== null && $row['digitable_line'] !== '' ? (string)$row['digitable_line'] : null,
                'pix_copy_paste' => $row['pix_copy_paste'] !== null && $row['pix_copy_paste'] !== '' ? (string)$row['pix_copy_paste'] : null,
                'portal_url' => $base . '/' . (int)$row['entry_id'],
            ];
        }
        return $out;
    }

    /** Morador ativo, no período, com unidade, no escopo do usuário de integração e com vínculo que paga. */
    private function resident(?User $agent, int $id, string $today): ?array
    {
        $allowed = $agent !== null ? $this->scope->allowedIds($agent) : [];
        if ($allowed === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($allowed), '?'));
        $roles = implode(',', array_fill(0, count(self::RELATIONSHIPS), '?'));
        $stmt = $this->pdo->prepare("SELECT r.condominium_id, r.unit_id, r.phone, c.name AS condominium_name,
                COALESCE(NULLIF(TRIM(u.label), ''), NULLIF(TRIM(r.unit_label), ''), '') AS unit
            FROM erp_residents r
            JOIN condominiums c ON c.id = r.condominium_id AND c.status = 'active'
            JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
            WHERE r.id = ? AND r.condominium_id IN ({$marks}) AND r.relationship IN ({$roles})
              AND r.status = 'active' AND r.deleted_at IS NULL
              AND (r.starts_on IS NULL OR r.starts_on <= ?) AND (r.ends_on IS NULL OR r.ends_on >= ?)");
        $stmt->execute([$id, ...$allowed, ...self::RELATIONSHIPS, $today, $today]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
