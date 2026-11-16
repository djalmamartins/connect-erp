<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/**
 * Tela "Fila de atendimento": quem está esperando, há quanto tempo, e os números do dia.
 * O escopo é o mesmo da caixa de entrada: supervisor vê tudo; os demais, os próprios setores.
 */
final class QueueQuery
{
    public const ONLINE_SECONDS = 60;

    public function __construct(private Db $db, private AccessPolicy $policy, private int $warnMinutes = 15, private int $lateMinutes = 30, private ?BusinessHours $hours = null)
    {
    }

    public function overview(StaffMember $u): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $waiting = $this->db->all(
            "SELECT c.id, c.protocol, c.sector_id, s.name AS sector_name, c.priority, c.queued_at, c.unread,
                    TIMESTAMPDIFF(SECOND, COALESCE(c.queued_at, c.opened_at), NOW()) AS wait_seconds,
                    COALESCE(c.queued_at, c.opened_at) AS wait_from, NOW() AS now_db,
                    ct.name AS contact_name, ct.phone,
                    (SELECT GROUP_CONCAT(tg.tag_id ORDER BY tg.tag_id) FROM talk_contact_tags tg WHERE tg.contact_id = c.contact_id) AS tag_ids,
                    (SELECT LEFT(COALESCE(NULLIF(m.body, ''), '(arquivo)'), 140) FROM talk_messages m
                      WHERE m.conversation_id = c.id AND m.direction = 'in' ORDER BY m.id LIMIT 1) AS subject
               FROM talk_conversations c
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id
              WHERE c.status = 'pending' AND $where
              ORDER BY COALESCE(c.queued_at, c.opened_at), c.id
              LIMIT 200",
            $params
        );
        foreach ($waiting as $i => &$w) {
            $w['position'] = $i + 1;
            // fora do horário do setor e feriados não contam como espera
            $w['wait_seconds'] = $this->hours
                ? $this->hours->openSeconds($w['sector_id'] !== null ? (int) $w['sector_id'] : null, new \DateTimeImmutable($w['wait_from']), new \DateTimeImmutable($w['now_db']))
                : (int) $w['wait_seconds'];
            // setor fechado agora: a espera fica parada até ele abrir
            $w['paused'] = $this->hours !== null && $w['sector_id'] !== null && !$this->hours->isOpen((int) $w['sector_id'], new \DateTimeImmutable($w['now_db']));
            unset($w['wait_from'], $w['now_db']);
            $w['level'] = $w['wait_seconds'] >= $this->lateMinutes * 60 ? 'late' : ($w['wait_seconds'] >= $this->warnMinutes * 60 ? 'warn' : 'ok');
        }
        unset($w);

        [$scope, $sp] = $this->sectorScope($u, 'c');
        $today = $this->db->one(
            "SELECT COUNT(*) AS total, SUM(c.status = 'closed') AS closed
               FROM talk_conversations c WHERE c.opened_at >= CURDATE() AND c.sector_id IS NOT NULL AND $scope",
            $sp
        );
        $total = (int) $today['total'];
        $waits = array_column($waiting, 'wait_seconds');

        // distribuição da fila por setor (inclui setores sem ninguém esperando)
        $sectors = $this->db->all('SELECT id, name, color FROM talk_sectors WHERE active = 1 ORDER BY menu_key');
        $visible = $u->supervisor ? null : $u->sectors();
        $dist = [];
        foreach ($sectors as $s) {
            if ($visible !== null && !in_array((int) $s['id'], $visible, true)) {
                continue;
            }
            $n = count(array_filter($waiting, fn ($w) => (int) $w['sector_id'] === (int) $s['id']));
            $dist[] = ['sector_id' => (int) $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'waiting' => $n];
        }

        return [
            'now' => $this->db->one('SELECT NOW() AS n')['n'],
            'thresholds' => ['warn_minutes' => $this->warnMinutes, 'late_minutes' => $this->lateMinutes],
            'stats' => [
                'waiting' => count($waiting),
                'ticking' => !array_filter($waiting, fn ($w) => $w['paused']), // números do topo correm sozinhos na tela
                'avg_wait_seconds' => $waits ? (int) round(array_sum($waits) / count($waits)) : 0,
                'max_wait_seconds' => $waits ? max($waits) : 0,
                'min_wait_seconds' => $waits ? min($waits) : 0,
                'today_total' => $total,
                'today_closed' => (int) $today['closed'],
                'resolution_rate' => $total > 0 ? (int) round(100 * (int) $today['closed'] / $total) : null,
            ],
            'distribution' => $dist,
            'attendants' => $this->attendants($u),
            'waiting' => $waiting,
        ];
    }

    /** Atendentes dos setores visíveis: online (conectado no último minuto), atendendo ou disponível. */
    public function attendants(StaffMember $u): array
    {
        $params = [];
        $filter = '';
        if (!$u->supervisor) {
            $sectors = array_map('intval', $u->sectors());
            $filter = $sectors ? ' AND s.sector_id IN (' . implode(',', $sectors) . ')' : ' AND 1=0';
        }
        $rows = $this->db->all(
            "SELECT u.id, u.name, MAX(s.role = 'supervisor') AS is_supervisor,
                    GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') AS sectors,
                    (u.last_seen_at IS NOT NULL AND u.last_seen_at >= NOW() - INTERVAL " . self::ONLINE_SECONDS . " SECOND) AS online,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.assigned_user_id = u.id AND c.status = 'open') AS open_count
               FROM talk_users u
               JOIN talk_staff s ON s.user_id = u.id
               LEFT JOIN talk_sectors sec ON sec.id = s.sector_id
              WHERE u.active = 1 $filter
              GROUP BY u.id, u.name, u.last_seen_at
              ORDER BY online DESC, u.name",
            $params
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'sectors' => $r['sectors'] ?: ((int) $r['is_supervisor'] ? 'Supervisão' : ''),
            'online' => (bool) $r['online'],
            'open_count' => (int) $r['open_count'],
            'status' => !$r['online'] ? 'offline' : ((int) $r['open_count'] > 0 ? 'busy' : 'available'),
        ], $rows);
    }

    public function touch(int $userId): void
    {
        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
    }

    /** @return array{0:string, 1:array} filtro de setor para os números do dia */
    private function sectorScope(StaffMember $u, string $alias): array
    {
        if ($u->supervisor) {
            return ['1=1', []];
        }
        $sectors = array_map('intval', $u->sectors());
        return [$sectors ? "$alias.sector_id IN (" . implode(',', $sectors) . ')' : '1=0', []];
    }
}
