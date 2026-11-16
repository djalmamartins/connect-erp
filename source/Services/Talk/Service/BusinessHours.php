<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/**
 * Horário de atendimento (tela Configurações, só supervisor).
 *
 * - Horário geral da semana (até 3 faixas por dia, ex.: 8h–12h e 13h–18h).
 * - Cada setor segue o geral, tem horário próprio ou atende 24 h (ex.: plantão da Manutenção).
 * - Feriados fecham o dia inteiro (menos para quem atende 24 h).
 * - Fora do horário, quem escreve recebe a mensagem configurada (uma vez por conversa), com o
 *   protocolo e quando o atendimento volta. A conversa fica na fila do setor normalmente.
 *
 * Desligado até o supervisor ligar: sem configuração, nada muda no atendimento.
 */
final class BusinessHours
{
    public const DAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    private const SHORT = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    public const DEFAULT_MESSAGE = "*Jack:*\nOi, {nome}! Recebi sua mensagem e abri o chamado *{protocolo}* para o setor *{setor}*. "
        . "Agora estamos fora do horário de atendimento ({horario}). A equipe responde {volta}.";

    private ?array $cache = null;
    private int $cachedAt = 0;

    public function __construct(private Db $db)
    {
    }

    public static function defaults(): array
    {
        $week = [];
        foreach (range(0, 6) as $d) {
            $week[(string) $d] = $d >= 1 && $d <= 5 ? [['08:00', '18:00']] : [];
        }
        return ['enabled' => false, 'week' => $week, 'sectors' => [], 'message' => self::DEFAULT_MESSAGE];
    }

    public function config(): array
    {
        // relido a cada 30 s: o worker roda sem parar e precisa ver a mudança feita na tela
        if ($this->cache === null || time() - $this->cachedAt > 30) {
            $row = $this->db->one("SELECT value FROM talk_settings WHERE name = 'hours'");
            $this->cache = array_replace(self::defaults(), $row ? (json_decode($row['value'], true) ?: []) : []);
            $this->cachedAt = time();
        }
        return $this->cache;
    }

    // ------------------------------------------------------------------ tela

    public function overview(StaffMember $u): array
    {
        $this->requireSupervisor($u);
        $cfg = $this->config();
        $now = $this->now();
        $sectors = [];
        foreach ($this->db->all('SELECT id, name, color FROM talk_sectors WHERE active = 1 ORDER BY menu_key, name') as $s) {
            $id = (int) $s['id'];
            $next = $this->isOpen($id, $now) ? null : $this->nextOpening($id, $now);
            $sectors[] = [
                'id' => $id, 'name' => $s['name'], 'color' => $s['color'],
                'mode' => $cfg['sectors'][(string) $id]['mode'] ?? 'default',
                'week' => $cfg['sectors'][(string) $id]['week'] ?? null,
                'open_now' => $this->isOpen($id, $now),
                'next_opening' => $next?->format('Y-m-d H:i'),
                'next_label' => $next ? $this->whenLabel($next, $now) : null,
                'summary' => $this->describe($this->weekFor($id) ?? []),
            ];
        }
        return [
            'config' => $cfg,
            'now' => $now->format('Y-m-d H:i'),
            'summary' => $this->describe($cfg['week']),
            'sectors' => $sectors,
            'holidays' => $this->holidays(),
            'variables' => ['{nome}' => 'primeiro nome do cliente', '{protocolo}' => 'número do chamado', '{setor}' => 'setor que vai atender',
                '{horario}' => 'horário do setor (ex.: seg a sex, 8h às 18h)', '{volta}' => 'quando volta (ex.: amanhã às 8h)'],
            // valores de exemplo para a prévia da mensagem (primeiro setor, como se estivesse fechado agora)
            'sample' => [
                '{nome}' => 'Mariana', '{protocolo}' => 'SAC' . $now->format('ymd') . '00042', '{setor}' => $sectors[0]['name'] ?? 'Financeiro',
                '{horario}' => $sectors[0]['summary'] ?? $this->describe($cfg['week']),
                '{volta}' => ($n = $this->nextOpening($sectors[0]['id'] ?? 0, $now, true)) && $n > $now ? $this->whenLabel($n, $now) : 'assim que possível',
            ],
        ];
    }

    public function save(StaffMember $u, array $in): array
    {
        $this->requireSupervisor($u);
        $cfg = [
            'enabled' => !empty($in['enabled']),
            'week' => $this->cleanWeek($in['week'] ?? null, 'Horário geral'),
            'sectors' => [],
            'message' => trim((string) ($in['message'] ?? '')),
        ];
        if ($cfg['message'] === '') {
            $cfg['message'] = self::DEFAULT_MESSAGE;
        }
        if (mb_strlen($cfg['message']) > 1000) {
            throw new DomainError('A mensagem pode ter até 1000 letras.');
        }
        $names = array_column($this->db->all('SELECT id, name FROM talk_sectors'), 'name', 'id');
        foreach ((array) ($in['sectors'] ?? []) as $id => $s) {
            if (!isset($names[$id])) {
                continue;
            }
            $mode = (string) ($s['mode'] ?? 'default');
            if ($mode === 'always') {
                $cfg['sectors'][(string) $id] = ['mode' => 'always'];
            } elseif ($mode === 'custom') {
                $cfg['sectors'][(string) $id] = ['mode' => 'custom', 'week' => $this->cleanWeek($s['week'] ?? null, $names[$id])];
            } elseif ($mode !== 'default') {
                throw new DomainError('Opção de horário inválida.');
            }
        }
        if ($cfg['enabled'] && !array_filter($cfg['week']) && !array_filter($cfg['sectors'], fn ($s) => $s['mode'] !== 'default')) {
            throw new DomainError('Marque pelo menos um dia de atendimento.');
        }
        $this->db->run(
            'INSERT INTO talk_settings (name, value, updated_by) VALUES (\'hours\', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
            [json_encode($cfg, JSON_UNESCAPED_UNICODE), $u->userId]
        );
        $this->audit($u, 'hours.update', null, ['enabled' => $cfg['enabled']]);
        $this->cache = null;
        return $this->overview($u);
    }

    public function holidays(): array
    {
        return array_map(fn ($h) => ['id' => (int) $h['id'], 'day' => $h['day'], 'name' => $h['name'],
            'weekday' => self::DAYS[(int) date('w', strtotime($h['day']))]],
            $this->db->all('SELECT id, day, name FROM talk_holidays WHERE day >= CURDATE() - INTERVAL 7 DAY ORDER BY day'));
    }

    public function addHoliday(StaffMember $u, array $in): array
    {
        $this->requireSupervisor($u);
        $day = trim((string) ($in['day'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$d || $d->format('Y-m-d') !== $day) {
            throw new DomainError('Informe a data do feriado.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainError('Dê um nome ao feriado (até 80 letras).');
        }
        if ($this->db->one('SELECT id FROM talk_holidays WHERE day = ?', [$day])) {
            throw new DomainError('Já existe um feriado nesse dia.');
        }
        $id = $this->db->insert('talk_holidays', ['day' => $day, 'name' => $name, 'created_by' => $u->userId]);
        $this->audit($u, 'holiday.create', $id, ['day' => $day, 'name' => $name]);
        return $this->holidays();
    }

    public function deleteHoliday(StaffMember $u, int $id): array
    {
        $this->requireSupervisor($u);
        $h = $this->db->one('SELECT * FROM talk_holidays WHERE id = ?', [$id]);
        if (!$h) {
            throw new DomainError('Feriado não encontrado.', 404);
        }
        $this->db->run('DELETE FROM talk_holidays WHERE id = ?', [$id]);
        $this->audit($u, 'holiday.delete', $id, ['day' => $h['day'], 'name' => $h['name']]);
        return $this->holidays();
    }

    /** Feriados nacionais do ano (fixos e os que dependem da Páscoa). Pula os que já existem. */
    public function importNational(StaffMember $u, int $year): array
    {
        $this->requireSupervisor($u);
        if ($year < 2000 || $year > 2100) {
            throw new DomainError('Ano inválido.');
        }
        $added = 0;
        foreach (self::nationalHolidays($year) as $day => $name) {
            if (!$this->db->one('SELECT id FROM talk_holidays WHERE day = ?', [$day])) {
                $this->db->insert('talk_holidays', ['day' => $day, 'name' => $name, 'created_by' => $u->userId]);
                $added++;
            }
        }
        $this->audit($u, 'holiday.import', null, ['year' => $year, 'added' => $added]);
        return ['added' => $added, 'holidays' => $this->holidays()];
    }

    /** @return array<string,string> Y-m-d => nome */
    public static function nationalHolidays(int $year): array
    {
        // Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano)
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $list = [
            "$year-01-01" => 'Confraternização Universal',
            $easter->modify('-48 days')->format('Y-m-d') => 'Carnaval',
            $easter->modify('-47 days')->format('Y-m-d') => 'Carnaval',
            $easter->modify('-2 days')->format('Y-m-d') => 'Sexta-feira Santa',
            "$year-04-21" => 'Tiradentes',
            "$year-05-01" => 'Dia do Trabalho',
            $easter->modify('+60 days')->format('Y-m-d') => 'Corpus Christi',
            "$year-09-07" => 'Independência do Brasil',
            "$year-10-12" => 'Nossa Senhora Aparecida',
            "$year-11-02" => 'Finados',
            "$year-11-15" => 'Proclamação da República',
            "$year-11-20" => 'Dia da Consciência Negra',
            "$year-12-25" => 'Natal',
        ];
        ksort($list);
        return $list;
    }

    // ------------------------------------------------------------------ regra

    public function enabled(): bool
    {
        return (bool) $this->config()['enabled'];
    }

    /** null = 24 h */
    private function weekFor(int $sectorId): ?array
    {
        $s = $this->config()['sectors'][(string) $sectorId] ?? null;
        return match ($s['mode'] ?? 'default') {
            'always' => null,
            'custom' => $s['week'],
            default => $this->config()['week'],
        };
    }

    public function isOpen(int $sectorId, \DateTimeImmutable $at): bool
    {
        if (!$this->enabled()) {
            return true;
        }
        $week = $this->weekFor($sectorId);
        if ($week === null) {
            return true;
        }
        if ($this->isHoliday($at->format('Y-m-d'))) {
            return false;
        }
        $hm = $at->format('H:i');
        foreach ($week[$at->format('w')] ?? [] as [$from, $to]) {
            if ($hm >= $from && $hm < $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * Segundos de espera que contam: só o tempo em que o setor estava atendendo (dentro do horário e
     * fora de feriado). Com o controle desligado ou setor 24 h, é o tempo corrido.
     */
    public function openSeconds(?int $sectorId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $total = $to->getTimestamp() - $from->getTimestamp();
        $week = $sectorId !== null && $this->enabled() ? $this->weekFor($sectorId) : null;
        if ($week === null) {
            return $total;
        }
        $days = (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%a');
        if ($days > 400) {
            return $total; // espera fora do comum: não vale o cálculo dia a dia
        }
        $holidays = array_flip(array_column($this->db->all(
            'SELECT day FROM talk_holidays WHERE day BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        ), 'day'));
        $sum = 0;
        for ($i = 0; $i <= $days; $i++) {
            $day = $from->setTime(0, 0)->modify("+$i day");
            if (isset($holidays[$day->format('Y-m-d')])) {
                continue;
            }
            foreach ($week[$day->format('w')] ?? [] as [$a, $b]) {
                [$ah, $am] = array_map('intval', explode(':', $a));
                [$bh, $bm] = array_map('intval', explode(':', $b));
                $start = max($from->getTimestamp(), $day->setTime($ah, $am)->getTimestamp());
                $end = min($to->getTimestamp(), $day->setTime($bh, $bm)->getTimestamp());
                $sum += max(0, $end - $start);
            }
        }
        return $sum;
    }

    /** @param bool $evenIfOff calcula mesmo com o controle desligado (prévia da mensagem) */
    public function nextOpening(int $sectorId, \DateTimeImmutable $at, bool $evenIfOff = false): ?\DateTimeImmutable
    {
        $week = $this->weekFor($sectorId);
        if ($week === null || (!$evenIfOff && !$this->enabled())) {
            return $at;
        }
        for ($i = 0; $i <= 31; $i++) {
            $day = $at->setTime(0, 0)->modify("+$i day");
            if ($this->isHoliday($day->format('Y-m-d'))) {
                continue;
            }
            foreach ($week[$day->format('w')] ?? [] as [$from]) {
                [$h, $m] = array_map('intval', explode(':', $from));
                $t = $day->setTime($h, $m);
                if ($t > $at) {
                    return $t;
                }
            }
        }
        return null;
    }

    /** Mensagem de fora do horário para esta conversa, ou null se o setor está atendendo agora. */
    public function offHoursMessage(int $sectorId, string $sectorName, ?string $firstName, string $protocol): ?string
    {
        $now = $this->now();
        if ($this->isOpen($sectorId, $now)) {
            return null;
        }
        return $this->render($this->config()['message'], $firstName, $protocol, $sectorName, $sectorId, $now);
    }

    private function render(string $tpl, ?string $first, string $protocol, string $sectorName, int $sectorId, \DateTimeImmutable $now): string
    {
        $next = $this->nextOpening($sectorId, $now);
        $text = strtr($tpl, [
            '{nome}' => $first ?? '',
            '{protocolo}' => $protocol,
            '{setor}' => $sectorName,
            '{horario}' => $this->describe($this->weekFor($sectorId) ?? []),
            '{volta}' => $next ? $this->whenLabel($next, $now) : 'assim que possível',
        ]);
        // "Oi, !" quando não sabemos o nome
        return (string) preg_replace(['/\s+,/u', '/,\s*!/u', '/ {2,}/'], [',', '!', ' '], $text);
    }

    /** "seg a sex, 8h às 12h e 13h às 18h; sáb, 8h às 12h" */
    public function describe(array $week): string
    {
        $fmt = function (string $t): string {
            [$h, $m] = explode(':', $t);
            return (int) $h . 'h' . ($m !== '00' ? $m : '');
        };
        $groups = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
            $iv = $week[(string) $d] ?? $week[$d] ?? [];
            if (!$iv) {
                continue;
            }
            $label = implode(' e ', array_map(fn ($p) => $fmt($p[0]) . ' às ' . $fmt($p[1]), $iv));
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['label'] === $label && $groups[$last]['to'] === ($d === 0 ? 6 : $d - 1)) {
                $groups[$last]['to'] = $d;
            } else {
                $groups[] = ['from' => $d, 'to' => $d, 'label' => $label];
            }
        }
        if (!$groups) {
            return 'sem horário definido';
        }
        return implode('; ', array_map(function ($g) {
            $days = $g['from'] === $g['to'] ? self::SHORT[$g['from']]
                : self::SHORT[$g['from']] . ($this->span($g['from'], $g['to']) > 1 ? ' a ' : ' e ') . self::SHORT[$g['to']];
            return "$days, {$g['label']}";
        }, $groups));
    }

    private function span(int $from, int $to): int
    {
        $order = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 0 => 6];
        return $order[$to] - $order[$from];
    }

    /** "hoje às 13h", "amanhã às 8h", "segunda-feira às 8h", "dia 26/12 às 8h" */
    public function whenLabel(\DateTimeImmutable $t, \DateTimeImmutable $now): string
    {
        $hour = (int) $t->format('G') . 'h' . ($t->format('i') !== '00' ? $t->format('i') : '');
        $days = (int) $now->setTime(0, 0)->diff($t->setTime(0, 0))->format('%a');
        return match (true) {
            $days === 0 => "hoje às $hour",
            $days === 1 => "amanhã às $hour",
            $days < 7 => self::DAYS[(int) $t->format('w')] . " às $hour",
            default => 'dia ' . $t->format('d/m') . " às $hour",
        };
    }

    private function isHoliday(string $day): bool
    {
        return (bool) $this->db->one('SELECT id FROM talk_holidays WHERE day = ?', [$day]);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->db->one('SELECT NOW() AS n')['n']);
    }

    private function cleanWeek(mixed $week, string $label): array
    {
        if (!is_array($week)) {
            throw new DomainError("$label: informe os horários da semana.");
        }
        $out = [];
        foreach (range(0, 6) as $d) {
            $iv = [];
            foreach ((array) ($week[(string) $d] ?? $week[$d] ?? []) as $p) {
                $from = trim((string) ($p[0] ?? ''));
                $to = trim((string) ($p[1] ?? ''));
                if ($from === '' && $to === '') {
                    continue;
                }
                foreach ([$from, $to] as $t) {
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) && $t !== '24:00') {
                        throw new DomainError("$label, " . self::DAYS[$d] . ': hora inválida.');
                    }
                }
                if ($from >= $to) {
                    throw new DomainError("$label, " . self::DAYS[$d] . ': o fim tem que ser depois do início.');
                }
                $iv[] = [$from, $to];
            }
            usort($iv, fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($iv); $i++) {
                if ($iv[$i][0] < $iv[$i - 1][1]) {
                    throw new DomainError("$label, " . self::DAYS[$d] . ': as faixas de horário se sobrepõem.');
                }
            }
            if (count($iv) > 3) {
                throw new DomainError("$label, " . self::DAYS[$d] . ': no máximo 3 faixas por dia.');
            }
            $out[(string) $d] = $iv;
        }
        return $out;
    }

    private function requireSupervisor(StaffMember $u): void
    {
        if (!$u->supervisor) {
            throw new DomainError('Só o supervisor altera o horário de atendimento.', 403);
        }
    }

    private function audit(StaffMember $u, string $action, ?int $id, array $details): void
    {
        $this->db->insert('talk_audit_log', [
            'user_id' => $u->userId, 'action' => $action, 'target_type' => 'settings', 'target_id' => $id,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
