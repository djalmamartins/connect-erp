<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Team\TeamPush;

/**
 * Push do app Meu Dia (ADR 0659), pelo cron de cada minuto (tools/my-day-push.php):
 *
 * - "Nova tarefa para você": quando alguém marca um compromisso para outra pessoa da equipe (POST /api/v1/my-day/events
 *   com assigned_to), a pessoa recebe um aviso no celular com quem marcou, o quê e quando. Só compromissos marcados
 *   nas últimas 24 horas e ainda em aberto.
 * - Resumo das 7h: a partir de MORNING_HOUR, uma vez por dia, para quem tem o app Meu Dia no celular: quantas tarefas
 *   estão atrasadas (a lista "overdue" do Meu Dia, ADR 0658) e quantos compromissos há no dia. Dia sem nada não avisa.
 *
 * Cada aviso sai uma vez só (my_day_push_log, gravado ANTES do envio: na dúvida, não repete). O envio e a lista de
 * aparelhos são do serviço de push da equipe (TeamPush, ADR 0657), que só chama o Expo com TEAM_PUSH_DELIVERY=expo.
 * O texto não leva dado pessoal de morador nem valor financeiro: título da tarefa, condomínio e horário.
 */
final class MyDayPush
{
    public const APP = 'meu-dia';
    public const MORNING_HOUR = 7;

    private PDO $pdo;
    private bool $enabled;
    /** @var callable(int, string, string, array<string,mixed>): void */
    private $send;
    /** @var callable(): list<int> */
    private $recipients;
    /** @var callable(int, DateTimeImmutable): ?array<string,mixed> */
    private $day;

    /**
     * @param (callable(int, string, string, array<string,mixed>): void)|null $send userId, título, texto, dados
     * @param (callable(): list<int>)|null $recipients quem tem o app Meu Dia no celular
     * @param (callable(int, DateTimeImmutable): ?array<string,mixed>)|null $day o Meu Dia da pessoa (MyDay::day)
     */
    public function __construct(?PDO $pdo = null, ?callable $send = null, ?callable $recipients = null, ?callable $day = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->enabled = $send !== null || class_exists(TeamPush::class);
        // Sem o serviço de push da equipe (ADR 0657) instalado, não envia nada e ninguém recebe o resumo.
        $this->send = $send ?? static function (int $userId, string $title, string $body, array $data): void {
            if (class_exists(TeamPush::class)) {
                TeamPush::send($userId, self::APP, $title, $body, $data);
            }
        };
        $this->recipients = $recipients ?? static fn (): array => class_exists(TeamPush::class) && method_exists(TeamPush::class, 'usersWithDevices')
            ? TeamPush::usersWithDevices(self::APP) : [];
        $this->day = $day ?? function (int $userId, DateTimeImmutable $today): ?array {
            $user = (new User())->findById($userId);
            if (!$user || (string)$user->status === 'trash') {
                return null;
            }
            return (new MyDay($this->pdo))->day($user, $today, $today);
        };
    }

    /** @return array{assigned:int, morning:int} quantos avisos saíram */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        if (!$this->enabled) {
            // Sem push da equipe instalado não grava nada, para os avisos saírem quando ele chegar.
            return ['assigned' => 0, 'morning' => 0];
        }
        return ['assigned' => $this->assigned($now), 'morning' => $this->morning($now)];
    }

    /** Avisa quem recebeu uma tarefa marcada por outra pessoa. */
    public function assigned(DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare("SELECT e.id, e.title, e.starts_at, e.assigned_to, c.name AS condominium,
                NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '') AS creator
            FROM studio_calendar_events e
            JOIN users u ON u.id = e.created_by
            LEFT JOIN condominiums c ON c.id = e.condominium_id
            WHERE e.assigned_to IS NOT NULL AND e.assigned_to <> e.created_by AND e.status = 'scheduled'
              AND e.created_at >= :since
              AND NOT EXISTS (SELECT 1 FROM my_day_push_log l WHERE l.user_id = e.assigned_to AND l.kind = 'assigned' AND l.ref = e.id)
            ORDER BY e.id LIMIT 200");
        $stmt->execute(['since' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $userId = (int)$row['assigned_to'];
            if (!$this->claim($userId, 'assigned', (string)$row['id'])) {
                continue;
            }
            [$title, $body] = self::assignedText((string)($row['creator'] ?? ''), (string)$row['title'], (string)$row['starts_at'], $row['condominium'] !== null ? (string)$row['condominium'] : null);
            ($this->send)($userId, $title, $body, ['app' => self::APP, 'kind' => 'assigned', 'id' => (int)$row['id'], 'date' => substr((string)$row['starts_at'], 0, 10)]);
            $sent++;
        }
        return $sent;
    }

    /** Resumo das 7h, uma vez por dia por pessoa. */
    public function morning(DateTimeImmutable $now): int
    {
        if ((int)$now->format('G') < self::MORNING_HOUR) {
            return 0;
        }
        $today = $now->setTime(0, 0);
        $key = $today->format('Y-m-d');
        $sent = 0;
        foreach (array_unique(array_map('intval', ($this->recipients)())) as $userId) {
            if ($userId <= 0 || !$this->claim($userId, 'morning', $key)) {
                continue;
            }
            $day = ($this->day)($userId, $today);
            if ($day === null) {
                continue;
            }
            $text = self::morningText($day);
            if ($text === null) {
                continue;
            }
            ($this->send)($userId, $text[0], $text[1], ['app' => self::APP, 'kind' => 'morning', 'date' => $key, 'overdue' => self::overdueCount($day)]);
            $sent++;
        }
        return $sent;
    }

    /** @return array{0:string, 1:string} título e texto do aviso de tarefa nova (regra pura) */
    public static function assignedText(string $creator, string $title, string $startsAt, ?string $condominium): array
    {
        $who = trim($creator) !== '' ? trim($creator) : 'Alguém da equipe';
        $when = date('d/m', strtotime($startsAt) ?: 0) . ' às ' . substr($startsAt, 11, 5);
        $body = mb_substr(trim($title), 0, 120) . ' · ' . $when . ($condominium !== null && $condominium !== '' ? ' · ' . $condominium : '');
        return ["{$who} marcou uma tarefa para você", $body];
    }

    /** @param array<string,mixed> $day */
    public static function overdueCount(array $day): int
    {
        if (isset($day['overdue']) && is_array($day['overdue'])) {
            return count($day['overdue']);
        }
        return (int)($day['summary']['overdue'] ?? 0);
    }

    /**
     * Título e texto do resumo da manhã (regra pura). null quando não há nada atrasado nem marcado.
     *
     * @param array<string,mixed> $day
     * @return array{0:string, 1:string}|null
     */
    public static function morningText(array $day): ?array
    {
        $late = self::overdueCount($day);
        $events = array_values(array_filter((array)($day['events'] ?? []), static fn ($e): bool => is_array($e) && ($e['status'] ?? '') === 'scheduled'));
        $toSchedule = (int)($day['summary']['to_schedule'] ?? 0);
        if ($late === 0 && $events === [] && $toSchedule === 0) {
            return null;
        }
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
        $title = $late > 0 ? 'Bom dia! ' . $plural($late, 'tarefa atrasada', 'tarefas atrasadas') : 'Bom dia! Seu dia no Connect';
        $parts = [$events !== [] ? $plural(count($events), 'compromisso', 'compromissos') . ' hoje (o primeiro às ' . (string)($events[0]['start'] ?? '') . ')' : 'Nenhum compromisso marcado hoje'];
        if ($toSchedule > 0) {
            $parts[] = $plural($toSchedule, 'chamado para agendar', 'chamados para agendar');
        }
        return [$title, implode(' · ', $parts) . '.'];
    }

    /** Grava que o aviso vai sair. false se já saiu antes (outra rodada do cron chegou primeiro). */
    private function claim(int $userId, string $kind, string $ref): bool
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO my_day_push_log (user_id, kind, ref) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $kind, $ref]);
        return $stmt->rowCount() === 1;
    }
}
