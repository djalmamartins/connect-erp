<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\AppLogger;

/**
 * Prazo da prestação de contas (ADR 0301): o livro do mês anterior precisa estar fechado e enviado aos moradores até o
 * 5º dia útil de cada mês (pedido do Djalma, 14/11/2026).
 *
 * Roda uma vez por dia (tools/accountability-deadline.php, pelo cron). Para cada condomínio ativo:
 * - livro enviado (link ativo com a versão atual enviada): nada a fazer;
 * - mês fechado e link pronto mas ainda não enviado (por exemplo, fechado antes deste recurso): envia aos moradores;
 * - faltando até 2 dias úteis para o prazo e o mês aberto: avisa a equipe ("due_soon");
 * - passou do prazo e o livro não foi enviado: avisa a equipe ("overdue").
 * Cada situação é avisada uma vez por condomínio e mês (erp_accountability_alerts). O aviso vai para o sino do
 * sistema (notifications) de quem pode fechar o mês (erp.closing.manage) e alcança o condomínio.
 *
 * Dia útil: segunda a sexta, menos os feriados nacionais e os dias sem expediente bancário (Carnaval, Sexta-feira
 * Santa e Corpus Christi). Feriados estaduais e municipais não entram (PENDENTE no ADR).
 */
final class AccountabilityDeadline
{
    public const BUSINESS_DAY = 5;
    public const WARN_BUSINESS_DAYS = 2;
    public const PERMISSION = 'erp.closing.manage';

    private PDO $pdo;
    private DateTimeImmutable $today;

    public function __construct(?PDO $pdo = null, ?DateTimeImmutable $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /** Domingo de Páscoa (regra pura, algoritmo de Meeus/Jones/Butcher). */
    public static function easter(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Feriados nacionais e dias sem expediente bancário do ano (regra pura). @return list<string> AAAA-MM-DD */
    public static function holidays(int $year): array
    {
        $fixed = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];
        $days = array_map(static fn (string $md): string => $year . '-' . $md, $fixed);
        $easter = self::easter($year);
        foreach (['-48 days', '-47 days', '-2 days', '+60 days'] as $shift) {   // Carnaval (seg e ter), Sexta-feira Santa, Corpus Christi
            $days[] = $easter->modify($shift)->format('Y-m-d');
        }
        sort($days);
        return $days;
    }

    public static function isBusinessDay(DateTimeImmutable $day): bool
    {
        return (int)$day->format('N') <= 5 && !in_array($day->format('Y-m-d'), self::holidays((int)$day->format('Y')), true);
    }

    /** N-ésimo dia útil do mês AAAA-MM (regra pura). */
    public static function businessDay(string $month, int $n = self::BUSINESS_DAY): DateTimeImmutable
    {
        $day = new DateTimeImmutable($month . '-01');
        $count = 0;
        while (true) {
            if (self::isBusinessDay($day) && ++$count === $n) {
                return $day;
            }
            $day = $day->modify('+1 day');
        }
    }

    /** Dias úteis de $from (exclusive) até $to (inclusive); 0 se $to não for depois (regra pura). */
    public static function businessDaysUntil(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $count = 0;
        for ($day = $from->modify('+1 day'); $day <= $to; $day = $day->modify('+1 day')) {
            $count += self::isBusinessDay($day) ? 1 : 0;
        }
        return $count;
    }

    /**
     * Situação do livro (regra pura): sent, send (pronto e não enviado), due_soon, overdue ou waiting.
     */
    public static function situation(DateTimeImmutable $today, DateTimeImmutable $deadline, bool $closed, bool $sent): string
    {
        if ($sent) {
            return 'sent';
        }
        if ($closed) {
            return 'send';
        }
        if ($today > $deadline) {
            return 'overdue';
        }
        return self::businessDaysUntil($today, $deadline) <= self::WARN_BUSINESS_DAYS ? 'due_soon' : 'waiting';
    }

    /** Mês do livro que vence neste mês (o anterior) e o prazo. @return array{competence:string, deadline:DateTimeImmutable} */
    public function current(): array
    {
        return [
            'competence' => $this->today->modify('first day of last month')->format('Y-m'),
            'deadline' => self::businessDay($this->today->format('Y-m')),
        ];
    }

    /** @return array{competence:string, deadline:string, condominiums:int, sent:int, sent_now:int, alerts:int, notified:int} */
    public function run(): array
    {
        ['competence' => $competence, 'deadline' => $deadline] = $this->current();
        $result = ['competence' => $competence, 'deadline' => $deadline->format('Y-m-d'), 'condominiums' => 0, 'sent' => 0, 'sent_now' => 0, 'alerts' => 0, 'notified' => 0];
        $stmt = $this->pdo->prepare("SELECT c.id, c.name, mc.status AS closing_status, l.id AS link_id, l.status AS link_status, l.version, l.sent_version
            FROM condominiums c
            LEFT JOIN erp_month_closings mc ON mc.condominium_id = c.id AND mc.competence = :m
            LEFT JOIN erp_accountability_links l ON l.condominium_id = c.id AND l.kind = 'monthly' AND l.competence = :m2
            WHERE c.status = 'active' ORDER BY c.id");
        $stmt->execute(['m' => $competence, 'm2' => $competence]);
        $category = (int)($this->pdo->query("SELECT id FROM notifications_categories WHERE uri = 'studio' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['condominiums']++;
            if ($row['link_status'] === 'revoked' && $row['closing_status'] === 'closed') {
                continue;   // a equipe revogou o link de propósito: não gera de novo sozinho
            }
            $sent = $row['link_status'] === 'active' && $row['sent_version'] !== null && (int)$row['sent_version'] === (int)$row['version'];
            $situation = self::situation($this->today, $deadline, $row['closing_status'] === 'closed', $sent);
            if ($situation === 'sent') {
                $result['sent']++;
                continue;
            }
            $condominium = (int)$row['id'];
            if ($situation === 'send') {
                if ($this->sendReady($condominium, $competence)) {
                    $result['sent_now']++;
                    continue;
                }
                $situation = $this->today > $deadline ? 'overdue' : 'due_soon';
            }
            if ($situation === 'waiting') {
                continue;
            }
            $insert = $this->pdo->prepare('INSERT IGNORE INTO erp_accountability_alerts (condominium_id, competence, situation) VALUES (:c, :m, :s)');
            $insert->execute(['c' => $condominium, 'm' => $competence, 's' => $situation]);
            if ($insert->rowCount() === 0) {
                continue;   // já avisado
            }
            $result['alerts']++;
            $notified = 0;
            if ($category > 0) {
                foreach ($this->managers($condominium) as $userId) {
                    $this->notify($userId, $category, (string)$row['name'], $competence, $deadline, $situation);
                    $notified++;
                }
            }
            $this->pdo->prepare('UPDATE erp_accountability_alerts SET notified = :n WHERE condominium_id = :c AND competence = :m AND situation = :s')
                ->execute(['n' => $notified, 'c' => $condominium, 'm' => $competence, 's' => $situation]);
            $result['notified'] += $notified;
        }
        return $result;
    }

    /** Texto do aviso à equipe (regra pura). */
    public static function message(string $condominium, string $competence, DateTimeImmutable $deadline, string $situation): string
    {
        $month = CashBookDocument::monthLabel($competence);
        return $situation === 'overdue'
            ? "O prazo da prestação de contas de {$month} do {$condominium} venceu em {$deadline->format('d/m/Y')}. Feche o mês para o livro ir aos moradores."
            : "A prestação de contas de {$month} do {$condominium} precisa ir aos moradores até {$deadline->format('d/m/Y')} (5º dia útil). Feche o mês para o livro sair.";
    }

    /** Mês fechado com link ainda não enviado: gera (ou atualiza) e envia em nome de quem fechou o mês. */
    private function sendReady(int $condominium, string $competence): bool
    {
        $stmt = $this->pdo->prepare("SELECT closed_by FROM erp_month_closings WHERE condominium_id = :c AND competence = :m AND status = 'closed'");
        $stmt->execute(['c' => $condominium, 'm' => $competence]);
        $closedBy = (int)($stmt->fetchColumn() ?: 0);
        $user = $closedBy > 0 ? (new User())->findById($closedBy) : null;
        if ($user === null) {
            return false;
        }
        try {
            $done = (new AccountabilityLinks($this->pdo))->afterClose($user, $condominium, $competence);
            return $done['monthly'] !== null;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'accountability_deadline_send_failed', 'condominium_id' => $condominium]);
            return false;
        }
    }

    private function notify(int $userId, int $category, string $condominium, string $competence, DateTimeImmutable $deadline, string $situation): void
    {
        $this->pdo->prepare("INSERT INTO notifications (users_id, category, image, title, body, severity, link, view, expires_at)
            VALUES (:user, :category, 'images/default.svg', :title, :body, :severity, :link, 0, DATE_ADD(NOW(), INTERVAL 1 MONTH))")
            ->execute([
                'user' => $userId, 'category' => $category,
                'title' => $situation === 'overdue' ? 'Prestação de contas atrasada' : 'Prestação de contas vence em breve',
                'body' => self::message($condominium, $competence, $deadline, $situation),
                'severity' => $situation === 'overdue' ? 'error' : 'warning',
                'link' => url('/erp/financeiro/fechamento-mensal?competencia=' . $competence),
            ]);
    }

    /** Quem pode fechar o mês e alcança o condomínio. @return list<int> */
    private function managers(int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT u.id FROM users u
            JOIN access_user_roles ur ON ur.user_id = u.id
            JOIN access_roles r ON r.id = ur.role_id
            JOIN access_permissions p ON p.slug = :perm
            LEFT JOIN access_user_overrides o ON o.user_id = u.id AND o.permission_id = p.id
            WHERE u.status = 'confirmed'
              AND (o.effect = 'allow' OR (COALESCE(o.effect, '') <> 'deny'
                   AND (r.slug = 'developer' OR EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id))))");
        $stmt->execute(['perm' => self::PERMISSION]);
        $scope = new CondominiumScope($this->pdo);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $user = (new User())->findById((int)$id);
            if ($user !== null && $scope->canAccess($user, $condominium)) {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }
}
