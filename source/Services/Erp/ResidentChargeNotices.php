<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Services\Erp\Banking\BankCharges;

/**
 * Avisos automáticos de cobrança ao morador (ADR 0601). Roda uma vez por dia pelo cron (tools/resident-charge-emails.php):
 *  - boleto que vence hoje: um e-mail "vence hoje" por unidade, com a linha digitável quando houver boleto;
 *  - boleto em atraso com 10, 15, 30, 45 ou 60 dias: um e-mail de atraso por unidade.
 *
 * "Boleto" é o título do banco (erp_bank_charges pendente ou registrado) ou, sem título, a conta a receber da unidade.
 * Cada boleto é avisado uma vez por marco (erp_resident_charge_notices, chave única): rodar de novo no mesmo dia, ou o
 * cron rodar duas vezes, não repete nada. Pago (ou cancelado) deixa de ser avisado. Se o cron falhar alguns dias, o
 * aviso de atraso ainda sai até ResidentEmailPolicy::CATCH_UP_DAYS depois do marco; o "vence hoje" só sai no dia.
 * Quem recebe é decidido na fila (NotificationDispatcher, público 'billing': só quem autorizou cobrança por e-mail).
 */
final class ResidentChargeNotices
{
    private PDO $pdo;
    private DateTimeImmutable $today;

    public function __construct(?PDO $pdo = null, ?DateTimeImmutable $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->today = $today ?? new DateTimeImmutable('today');
    }

    /** Linha de um boleto no e-mail (regra pura). */
    public static function line(string $description, int $amountCents, string $dueOn, int $daysLate): string
    {
        $text = ($description !== '' ? $description : 'Boleto') . ' · R$ ' . number_format($amountCents / 100, 2, ',', '.')
            . ' · vencimento ' . date('d/m/Y', (int)strtotime($dueOn));
        return $daysLate > 0 ? $text . " · {$daysLate} dias de atraso" : $text;
    }

    /** @return array{date:string, due_today:int, overdue:int, units:int, skipped:int} */
    public function run(): array
    {
        $today = $this->today->format('Y-m-d');
        $oldest = $this->today->modify('-' . (max(ResidentEmailPolicy::MILESTONES) + ResidentEmailPolicy::CATCH_UP_DAYS) . ' days')->format('Y-m-d');
        $summary = ['date' => $today, 'due_today' => 0, 'overdue' => 0, 'units' => 0, 'skipped' => 0];
        $seen = $this->pdo->prepare('SELECT 1 FROM erp_resident_charge_notices WHERE item_type = :t AND item_id = :i AND milestone = :m');
        $groups = [];
        foreach ($this->items($oldest, $today) as $item) {
            $daysLate = (int)$this->today->diff(new DateTimeImmutable($item['due_on']))->format('%a');
            $milestone = $item['due_on'] === $today ? 0 : ResidentEmailPolicy::overdueMilestone($daysLate);
            if ($milestone === null) {
                continue;
            }
            $seen->execute(['t' => $item['item_type'], 'i' => (int)$item['item_id'], 'm' => $milestone]);
            if ($seen->fetchColumn()) {
                $summary['skipped']++;
                continue;
            }
            $kind = $milestone === 0 ? 'charge.due_today' : 'charge.overdue';
            $key = $kind . ':' . $item['condominium_id'] . ':' . $item['unit_id'];
            $groups[$key] ??= ['kind' => $kind, 'condominium_id' => (int)$item['condominium_id'], 'unit_id' => (int)$item['unit_id'],
                'unit_label' => (string)$item['unit_label'], 'items' => []];
            $groups[$key]['items'][] = $item + ['milestone' => $milestone, 'days_late' => $milestone === 0 ? 0 : $daysLate];
        }
        $outbox = new NotificationOutbox($this->pdo);
        $mark = $this->pdo->prepare('INSERT IGNORE INTO erp_resident_charge_notices (condominium_id, unit_id, item_type, item_id, milestone, notified_on, event_key)
            VALUES (:c, :u, :t, :i, :m, :d, :k)');
        foreach ($groups as $group) {
            $ids = array_map(static fn (array $i): string => $i['item_type'][0] . $i['item_id'] . '-' . $i['milestone'], $group['items']);
            sort($ids);
            $eventKey = $group['kind'] . ':' . $group['unit_id'] . ':' . $today . ':' . substr(sha1(implode(',', $ids)), 0, 10);
            $this->pdo->beginTransaction();
            try {
                $marked = 0;
                foreach ($group['items'] as $item) {
                    $mark->execute(['c' => $group['condominium_id'], 'u' => $group['unit_id'], 't' => $item['item_type'], 'i' => (int)$item['item_id'],
                        'm' => $item['milestone'], 'd' => $today, 'k' => $eventKey]);
                    $marked += $mark->rowCount();
                }
                if ($marked === 0) {
                    $this->pdo->rollBack();   // outro processo marcou antes: não repete
                    $summary['skipped'] += count($group['items']);
                    continue;
                }
                $outbox->record($group['kind'], $eventKey, $group['condominium_id'], 'erp_units', $group['unit_id'], self::payload($group), null);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
            $summary['units']++;
            $summary[$group['kind'] === 'charge.due_today' ? 'due_today' : 'overdue'] += count($group['items']);
        }
        return $summary;
    }

    /** Payload do aviso de uma unidade (regra pura): total, e o vencimento e a linha digitável quando é um boleto só. */
    public static function payload(array $group): array
    {
        $items = $group['items'];
        $single = count($items) === 1 ? $items[0] : null;
        return [
            'unit_id' => $group['unit_id'], 'unit_label' => $group['unit_label'], 'items' => count($items),
            'amount_cents' => array_sum(array_map(static fn (array $i): int => (int)$i['amount_cents'], $items)),
            'due_on' => $single !== null ? (string)$single['due_on'] : '',
            'description' => $single !== null ? (string)$single['description'] : '',
            'days_late' => max(array_map(static fn (array $i): int => (int)$i['days_late'], $items)),
            'digitable_line' => $single !== null ? (string)($single['digitable_line'] ?? '') : '',
            'lines' => implode("\n", array_map(static fn (array $i): string => self::line((string)$i['description'], (int)$i['amount_cents'], (string)$i['due_on'],
                (int)$i['days_late']), array_slice($items, 0, 6))),
        ];
    }

    /** Boletos em aberto de unidades com vencimento na janela (títulos do banco e contas a receber sem título). */
    private function items(string $from, string $to): array
    {
        $charges = $this->pdo->prepare("SELECT 'charge' AS item_type, ch.id AS item_id, ch.condominium_id, e.unit_id, u.label AS unit_label,
                e.description, ch.amount_cents, ch.due_on, ch.digitable_line
            FROM erp_bank_charges ch
            JOIN erp_entries e ON e.id = ch.entry_id
            JOIN erp_units u ON u.id = e.unit_id
            JOIN condominiums c ON c.id = ch.condominium_id
            WHERE ch.status IN ('pending', 'registered') AND ch.due_on BETWEEN :f AND :t
              AND e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NULL AND c.status = 'active'
            ORDER BY ch.condominium_id, e.unit_id, ch.due_on, ch.id");
        $charges->execute(['f' => $from, 't' => $to]);
        // Conta a receber sem boleto ativo (nem principal nem dentro de um boleto único, ADR 0331).
        $entries = $this->pdo->prepare("SELECT 'entry' AS item_type, e.id AS item_id, e.condominium_id, e.unit_id, u.label AS unit_label,
                e.description, e.amount_cents, e.due_on, NULL AS digitable_line
            FROM erp_entries e
            JOIN erp_units u ON u.id = e.unit_id
            JOIN condominiums c ON c.id = e.condominium_id
            WHERE e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on IS NULL
              AND e.unit_id IS NOT NULL AND e.due_on BETWEEN :f AND :t AND c.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM " . BankCharges::COVERAGE . " cv WHERE cv.entry_id = e.id AND cv.status IN ('pending', 'registered', 'paid'))
            ORDER BY e.condominium_id, e.unit_id, e.due_on, e.id");
        $entries->execute(['f' => $from, 't' => $to]);
        return array_merge($charges->fetchAll(PDO::FETCH_ASSOC), $entries->fetchAll(PDO::FETCH_ASSOC));
    }
}
