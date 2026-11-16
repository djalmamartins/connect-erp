<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Erp\CollectionReports;
use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/**
 * Tela "Painel": números de um período (padrão: últimos 7 dias).
 * Escopo: supervisor vê tudo; gerente, os setores que gerencia; atendente, só os próprios atendimentos.
 *
 * Tempos:
 * - espera: de quando entrou na fila até alguém aceitar (a primeira vez), contando só o tempo em
 *   que o setor estava atendendo (fora do horário e feriados não contam);
 * - atendimento: de quando foi aceito até encerrar.
 * A conversa entra no período pelo dia em que foi aberta.
 */
final class ReportService
{
    public const MAX_DAYS = 366;

    public function __construct(private Db $db, private ?BusinessHours $hours = null)
    {
    }

    /** @param array{from?:string, to?:string, sector_id?:int|string, user_id?:int|string} $f */
    public function summary(StaffMember $u, array $f = []): array
    {
        [$from, $to] = $this->period($f);
        $rows = $this->rows($u, $from, $to, $f);
        $scope = $this->scopeLabel($u);

        $kpis = $this->aggregate($rows);
        $kpis['unattended'] = count(array_filter($rows, fn ($r) => $r['status'] === 'closed' && $r['accepted_at'] === null));

        // por dia (inclui dias sem movimento)
        $days = [];
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'total' => 0, 'closed' => 0];
        }
        foreach ($rows as $r) {
            $k = substr($r['opened_at'], 0, 10);
            if (isset($days[$k])) {
                $days[$k]['total']++;
                $days[$k]['closed'] += $r['status'] === 'closed' ? 1 : 0;
            }
        }
        // por hora do dia (quando as pessoas mais escrevem)
        $hours = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            $hours[(int) substr($r['opened_at'], 11, 2)]++;
        }

        $bySector = [];
        foreach ($this->group($rows, 'sector_id') as $id => $g) {
            $bySector[] = ['id' => (int) $id, 'name' => $g[0]['sector_name']] + $this->aggregate($g);
        }
        usort($bySector, fn ($a, $b) => $b['total'] <=> $a['total']);

        $byUser = [];
        foreach ($this->group(array_filter($rows, fn ($r) => $r['assigned_user_id'] !== null), 'assigned_user_id') as $id => $g) {
            $byUser[] = ['id' => (int) $id, 'name' => $g[0]['user_name'] ?? '(removido)'] + $this->aggregate($g);
        }
        usort($byUser, fn ($a, $b) => $b['closed'] <=> $a['closed'] ?: strcmp($a['name'], $b['name']));

        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $r) {
            if ($r['score'] !== null) {
                $dist[(int) $r['score']]++;
            }
        }
        $comments = array_values(array_filter($rows, fn ($r) => $r['score'] !== null));
        usort($comments, fn ($a, $b) => strcmp((string) $b['answered_at'], (string) $a['answered_at']));
        $comments = array_map(fn ($r) => [
            'conversation_id' => (int) $r['id'], 'protocol' => $r['protocol'], 'contact' => $r['contact_name'] ?: $r['phone'],
            'user' => $r['user_name'], 'sector' => $r['sector_name'], 'score' => (int) $r['score'],
            'comment' => $r['comment'], 'answered_at' => $r['answered_at'],
        ], array_slice($comments, 0, 12));

        return [
            'period' => ['from' => $from, 'to' => $to],
            'scope' => $scope,
            'kpis' => $kpis,
            'by_day' => array_values($days),
            'by_hour' => $hours,
            'by_sector' => $bySector,
            'by_user' => $byUser,
            'ratings' => ['distribution' => $dist, 'latest' => $comments],
            'filters' => $this->filterOptions($u),
        ];
    }

    /** Planilha (CSV, separada por ponto e vírgula, abre direto no Excel) com um atendimento por linha. */
    public function exportCsv(StaffMember $u, array $f = []): string
    {
        [$from, $to] = $this->period($f);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos certos no Excel
        // Cada célula passa por csvCell (ADR 0081): o nome do contato vem do WhatsApp e pode ser "=HYPERLINK(...)".
        $csvCell = static fn (array $row): array => array_map(static fn ($v): string => CollectionReports::csvCell((string) $v), $row);
        fputcsv($out, $csvCell(['Protocolo', 'Aberto em', 'Contato', 'Telefone', 'Setor', 'Atendente', 'Situação', 'Aceito em',
            'Encerrado em', 'Espera (min)', 'Atendimento (min)', 'Nota', 'Comentário']), ';', '"', '');
        $status = ['bot' => 'Com a Jack', 'pending' => 'Na fila', 'open' => 'Em atendimento', 'closed' => 'Encerrado'];
        foreach ($this->rows($u, $from, $to, $f) as $r) {
            fputcsv($out, $csvCell([
                $r['protocol'], $this->br($r['opened_at']), $r['contact_name'], $r['phone'], $r['sector_name'], $r['user_name'],
                $status[$r['status']] ?? $r['status'], $this->br($r['accepted_at']), $this->br($r['closed_at']),
                $r['wait_s'] !== null ? number_format($r['wait_s'] / 60, 1, ',', '') : '',
                $r['handle_s'] !== null ? number_format($r['handle_s'] / 60, 1, ',', '') : '',
                $r['score'] ?? '', $r['comment'] ?? '',
            ]), ';', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    // ------------------------------------------------------------------ interno

    /** @return array{0:string, 1:string} datas Y-m-d (inclusive) */
    private function period(array $f): array
    {
        $today = substr($this->db->one('SELECT NOW() AS n')['n'], 0, 10);
        $to = $this->date($f['to'] ?? '') ?? $today;
        $from = $this->date($f['from'] ?? '') ?? date('Y-m-d', strtotime("$to -6 days"));
        if ($from > $to) {
            throw new DomainError('A data inicial é depois da final.');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
            throw new DomainError('Escolha um período de até um ano.');
        }
        return [$from, $to];
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new DomainError('Data inválida.');
        }
        return $v;
    }

    private function rows(StaffMember $u, string $from, string $to, array $f): array
    {
        $where = ['c.opened_at >= ?', 'c.opened_at < ? + INTERVAL 1 DAY', 'c.sector_id IS NOT NULL'];
        $params = [$from . ' 00:00:00', $to . ' 00:00:00'];
        if (!$u->supervisor) {
            if ($u->managerSectors) {
                $where[] = 'c.sector_id IN (' . implode(',', array_map('intval', $u->managerSectors)) . ')';
            } else {
                $where[] = 'c.assigned_user_id = ?';
                $params[] = $u->userId;
            }
        }
        if (($f['sector_id'] ?? '') !== '' && ($f['sector_id'] ?? null) !== null) {
            $where[] = 'c.sector_id = ?';
            $params[] = (int) $f['sector_id'];
        }
        if (($f['user_id'] ?? '') !== '' && ($f['user_id'] ?? null) !== null) {
            $where[] = 'c.assigned_user_id = ?';
            $params[] = (int) $f['user_id'];
        }
        $rows = $this->db->all(
            'SELECT c.id, c.protocol, c.status, c.sector_id, s.name AS sector_name, c.assigned_user_id, us.name AS user_name,
                    ct.name AS contact_name, ct.phone, c.opened_at, c.accepted_at, c.closed_at,
                    CASE WHEN c.accepted_at IS NULL THEN NULL
                         ELSE GREATEST(0, TIMESTAMPDIFF(SECOND,
                              CASE WHEN c.queued_at IS NOT NULL AND c.queued_at <= c.accepted_at THEN c.queued_at ELSE c.opened_at END,
                              c.accepted_at)) END AS wait_s,
                    CASE WHEN c.queued_at IS NOT NULL AND c.queued_at <= c.accepted_at THEN c.queued_at ELSE c.opened_at END AS wait_from,
                    CASE WHEN c.accepted_at IS NULL OR c.closed_at IS NULL OR c.status <> \'closed\' THEN NULL
                         ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, c.accepted_at, c.closed_at)) END AS handle_s,
                    r.score, r.comment, r.answered_at, (r.id IS NOT NULL) AS rating_asked
               FROM talk_conversations c
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id
               LEFT JOIN talk_users us ON us.id = c.assigned_user_id
               LEFT JOIN talk_ratings r ON r.conversation_id = c.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY c.opened_at, c.id',
            $params
        );
        foreach ($rows as &$r) {
            $r['assigned_user_id'] = $r['assigned_user_id'] !== null ? (int) $r['assigned_user_id'] : null;
            $r['wait_s'] = $r['wait_s'] === null ? null : ($this->hours
                ? $this->hours->openSeconds($r['sector_id'] !== null ? (int) $r['sector_id'] : null, new \DateTimeImmutable($r['wait_from']), new \DateTimeImmutable($r['accepted_at']))
                : (int) $r['wait_s']);
            unset($r['wait_from']);
            $r['handle_s'] = $r['handle_s'] !== null ? (int) $r['handle_s'] : null;
            $r['rating_asked'] = (bool) $r['rating_asked'];
        }
        return $rows;
    }

    private function aggregate(array $rows): array
    {
        $avg = function (array $v): ?int {
            $v = array_values(array_filter($v, fn ($x) => $x !== null));
            return $v ? (int) round(array_sum($v) / count($v)) : null;
        };
        $scores = array_values(array_filter(array_column($rows, 'score'), fn ($x) => $x !== null));
        $asked = count(array_filter($rows, fn ($r) => $r['rating_asked']));
        $total = count($rows);
        $closed = count(array_filter($rows, fn ($r) => $r['status'] === 'closed'));
        return [
            'total' => $total,
            'closed' => $closed,
            'open' => $total - $closed,
            'resolution_rate' => $total ? (int) round(100 * $closed / $total) : null,
            'avg_wait_seconds' => $avg(array_column($rows, 'wait_s')),
            'avg_handle_seconds' => $avg(array_column($rows, 'handle_s')),
            'rating_avg' => $scores ? round(array_sum(array_map('intval', $scores)) / count($scores), 1) : null,
            'rating_count' => count($scores),
            'rating_asked' => $asked,
        ];
    }

    private function group(array $rows, string $key): array
    {
        $g = [];
        foreach ($rows as $r) {
            $g[$r[$key]][] = $r;
        }
        return $g;
    }

    private function filterOptions(StaffMember $u): array
    {
        if (!$u->supervisor && !$u->managerSectors) {
            return ['sectors' => [], 'users' => []]; // atendente: só os próprios números
        }
        $sectorWhere = $u->supervisor ? '' : ' AND id IN (' . implode(',', array_map('intval', $u->managerSectors)) . ')';
        $sectors = $this->db->all("SELECT id, name FROM talk_sectors WHERE 1=1 $sectorWhere ORDER BY name");
        $userWhere = $u->supervisor ? '' : ' AND st.sector_id IN (' . implode(',', array_map('intval', $u->managerSectors)) . ')';
        $users = $this->db->all("SELECT DISTINCT us.id, us.name FROM talk_users us JOIN talk_staff st ON st.user_id = us.id WHERE 1=1 $userWhere ORDER BY us.name");
        return [
            'sectors' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $sectors),
            'users' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $users),
        ];
    }

    private function scopeLabel(StaffMember $u): string
    {
        return $u->supervisor ? 'all' : ($u->managerSectors ? 'sectors' : 'mine');
    }

    private function br(?string $dt): string
    {
        return $dt ? date('d/m/Y H:i', strtotime($dt)) : '';
    }
}
