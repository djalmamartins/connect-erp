<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

use DateTimeImmutable;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\AppLogger;

/**
 * Vistoria que vira chamado no SAC (ADR 0630).
 *
 * - run(): rotina diária. Para cada plano ativo cuja vistoria vence em breve ou já venceu, abre um chamado
 *   "Vistoria ..." para o responsável do plano. Um chamado por plano e por data devida (chave única), então
 *   rodar de novo no mesmo dia não repete.
 * - completed(): quando a vistoria do plano é concluída, resolve o chamado aberto e, se houve itens com
 *   problema, abre um chamado de pendências com a lista, para não se perder até a próxima visita.
 */
final class InspectionTickets
{
    public const CATEGORY = 'Vistoria';
    public const ISSUES_CATEGORY = 'Pendência de vistoria';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null, private ?TicketService $tickets = null, private ?TicketDesk $desk = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** @return array{plans:int, opened:int, skipped:int} */
    public function run(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $rows = $this->pdo->query("SELECT p.id, p.condominium_id, c.name AS condominium, t.name AS template, p.frequency, p.starts_on,
                p.responsible_id, p.created_by,
                (SELECT MAX(i.completed_at) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'completed') AS last_completed_at,
                (SELECT COUNT(*) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'in_progress') AS open_inspections
            FROM inspection_plans p
            JOIN condominiums c ON c.id = p.condominium_id AND c.status = 'active'
            JOIN inspection_templates t ON t.id = p.template_id AND t.status = 'active'
            WHERE p.status = 'active'
            ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = ['plans' => count($rows), 'opened' => 0, 'skipped' => 0];
        $exists = $this->pdo->prepare('SELECT 1 FROM inspection_plan_tickets WHERE plan_id = ? AND due_on = ?');
        foreach ($rows as $row) {
            $last = $row['last_completed_at'] ? new DateTimeImmutable((string)$row['last_completed_at']) : null;
            $due = Schedule::nextDue((string)$row['frequency'], new DateTimeImmutable((string)$row['starts_on']), $last);
            $status = Schedule::status((string)$row['frequency'], $due, $today);
            if ($status === 'ok' || (int)$row['open_inspections'] > 0) {
                continue;
            }
            $exists->execute([(int)$row['id'], $due->format('Y-m-d')]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $owner = $row['responsible_id'] !== null ? (int)$row['responsible_id'] : ($row['created_by'] !== null ? (int)$row['created_by'] : null);
            if ($owner === null) {
                $result['skipped']++;
                continue;
            }
            try {
                $this->openForPlan($row, $due, $status === 'overdue', $owner, $today);
                $result['opened']++;
            } catch (PDOException $error) {
                // Outra execução abriu o mesmo chamado ao mesmo tempo (chave única): nada a fazer.
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
            } catch (TicketError $error) {
                $result['skipped']++;
                AppLogger::exception($error, 'inspections', ['event_type' => 'inspection_ticket_failed', 'plan_id' => (int)$row['id']]);
            }
        }
        return $result;
    }

    /**
     * Vistoria concluída: resolve o chamado do plano e abre o chamado das pendências encontradas.
     *
     * @return array{resolved:list<int>, issues_ticket:?int}
     */
    public function completed(User $user, int $inspectionId): array
    {
        $stmt = $this->pdo->prepare("SELECT i.id, i.plan_id, i.condominium_id, i.template_name, c.name AS condominium, p.responsible_id
            FROM inspections i JOIN condominiums c ON c.id = i.condominium_id LEFT JOIN inspection_plans p ON p.id = i.plan_id
            WHERE i.id = ? AND i.status = 'completed'");
        $stmt->execute([$inspectionId]);
        $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($inspection)) {
            return ['resolved' => [], 'issues_ticket' => null];
        }

        $resolved = [];
        if ($inspection['plan_id'] !== null) {
            $open = $this->pdo->prepare("SELECT pt.id, pt.ticket_id FROM inspection_plan_tickets pt
                JOIN studio_support_tickets t ON t.id = pt.ticket_id
                WHERE pt.plan_id = ? AND pt.inspection_id IS NULL AND t.status NOT IN ('resolved', 'closed')");
            $open->execute([(int)$inspection['plan_id']]);
            $link = $this->pdo->prepare('UPDATE inspection_plan_tickets SET inspection_id = ? WHERE id = ?');
            foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->desk()->update($user, (int)$row['ticket_id'], ['status' => 'resolved']);
                $link->execute([$inspectionId, (int)$row['id']]);
                $resolved[] = (int)$row['ticket_id'];
            }
        }

        $issues = $this->pdo->prepare("SELECT section, label, note FROM inspection_answers WHERE inspection_id = ? AND result = 'issue' ORDER BY position");
        $issues->execute([$inspectionId]);
        $list = $issues->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $issuesTicket = null;
        if ($list !== []) {
            $items = '';
            foreach ($list as $issue) {
                $where = trim((string)($issue['section'] ?? '')) !== '' ? $issue['section'] . ': ' : '';
                $note = trim((string)($issue['note'] ?? ''));
                $items .= '<li>' . self::e($where . $issue['label']) . ($note !== '' ? ' — ' . self::e($note) : '') . '</li>';
            }
            $opened = $this->tickets()->open([
                'subject' => self::subject('Pendências da vistoria', (string)$inspection['condominium']),
                'message' => '<p>A vistoria ' . self::e((string)$inspection['template_name']) . ' de ' . date('d/m/Y')
                    . ' encontrou ' . count($list) . ' ' . (count($list) === 1 ? 'problema' : 'problemas') . ':</p><ul>' . $items . '</ul>'
                    . '<p>As fotos estão na vistoria nº ' . $inspectionId . ' (Agenda › Vistorias).</p>',
                'priority' => 'high',
                'category' => self::ISSUES_CATEGORY,
                'condominium_id' => (int)$inspection['condominium_id'],
                'assigned_to' => $inspection['responsible_id'] !== null ? (int)$inspection['responsible_id'] : (int)$user->id,
            ], 'erp', $user);
            $issuesTicket = $opened['id'];
        }
        return ['resolved' => $resolved, 'issues_ticket' => $issuesTicket];
    }

    /** @param array<string, mixed> $plan */
    private function openForPlan(array $plan, DateTimeImmutable $due, bool $overdue, int $owner, DateTimeImmutable $today): void
    {
        $frequency = mb_strtolower(Schedule::label((string)$plan['frequency']));
        $this->tickets()->open([
            'subject' => self::subject('Vistoria ' . $frequency, (string)$plan['condominium']),
            'message' => '<p>A vistoria ' . self::e((string)$plan['template']) . ' (' . self::e($frequency) . ') do '
                . self::e((string)$plan['condominium']) . ' ' . ($overdue ? 'venceu' : 'vence') . ' em ' . $due->format('d/m/Y') . '.</p>'
                . '<p>Faça a vistoria em Agenda › Vistorias. Ao concluir, este chamado é resolvido sozinho.</p>',
            'priority' => $overdue ? 'high' : 'medium',
            'category' => self::CATEGORY,
            'condominium_id' => (int)$plan['condominium_id'],
            'requester_id' => $owner,
            'assigned_to' => $plan['responsible_id'] !== null ? (int)$plan['responsible_id'] : null,
        ], 'erp', null, function (PDO $pdo, int $ticketId) use ($plan, $due, $today): void {
            // O prazo do chamado é o fim do dia da vistoria (vencida: o fim do dia da rodada), não o SLA da prioridade.
            $deadline = max($due, $today)->format('Y-m-d') . ' 18:00:00';
            $pdo->prepare('UPDATE studio_support_tickets SET due_at = ? WHERE id = ?')->execute([$deadline, $ticketId]);
            $pdo->prepare('INSERT INTO inspection_plan_tickets (plan_id, due_on, ticket_id) VALUES (?, ?, ?)')
                ->execute([(int)$plan['id'], $due->format('Y-m-d'), $ticketId]);
        });
    }

    private static function subject(string $what, string $condominium): string
    {
        return mb_substr($what . ' – ' . $condominium, 0, 180);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function tickets(): TicketService
    {
        return $this->tickets ??= new TicketService($this->pdo);
    }

    private function desk(): TicketDesk
    {
        return $this->desk ??= new TicketDesk($this->pdo);
    }
}
