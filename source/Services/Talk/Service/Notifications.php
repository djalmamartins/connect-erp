<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/**
 * O sino do topo: o que pede a atenção da pessoa agora, calculado na hora (não há "lida"):
 * - conversas esperando na fila dos setores dela;
 * - conversas dela com mensagem do cliente ainda não lida;
 * - agendamentos que falharam nos últimos 7 dias (dos setores dela ou criados por ela);
 * - supervisor: números de WhatsApp ativos que caíram.
 * Cada item some quando o motivo some.
 */
final class Notifications
{
    public function __construct(private Db $db, private AccessPolicy $policy, private ?BusinessHours $hours = null)
    {
    }

    /** @return array{count:int, items:list<array{kind:string,title:string,text:string,href:string,count:int}>} */
    public function for(StaffMember $u): array
    {
        $items = [];
        $plural = static fn (int $n, string $one, string $many) => $n === 1 ? $one : str_replace('#', (string) $n, $many);

        // fila: só o que a pessoa pode pegar (o filtro da caixa de entrada já cobre setor e papel)
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $q = $this->db->one(
            "SELECT COUNT(*) n, TIMESTAMPDIFF(MINUTE, MIN(COALESCE(c.queued_at, c.opened_at)), NOW()) wait_min FROM talk_conversations c WHERE c.status = 'pending' AND c.sector_id IS NOT NULL AND $where",
            $params
        );
        if ((int) $q['n'] > 0) {
            $min = max(0, (int) $q['wait_min']);
            if ($this->hours !== null) {
                // espera só conta com o setor atendendo (fora do horário e feriados, não)
                $rows = $this->db->all(
                    "SELECT c.sector_id, COALESCE(c.queued_at, c.opened_at) t, NOW() now_db FROM talk_conversations c WHERE c.status = 'pending' AND c.sector_id IS NOT NULL AND $where",
                    $params
                );
                $min = 0;
                foreach ($rows as $r) {
                    $min = max($min, intdiv($this->hours->openSeconds((int) $r['sector_id'], new \DateTimeImmutable($r['t']), new \DateTimeImmutable($r['now_db'])), 60));
                }
            }
            $items[] = ['kind' => 'queue', 'count' => (int) $q['n'], 'href' => '/fila',
                'title' => $plural((int) $q['n'], '1 conversa esperando na fila', '# conversas esperando na fila'),
                'text' => $min < 1 ? 'A mais antiga chegou agora.' : "A mais antiga espera há {$this->duration($min)}."];
        }

        $m = $this->db->one(
            "SELECT COUNT(*) n, SUM(c.unread) msgs FROM talk_conversations c WHERE c.status = 'open' AND c.assigned_user_id = ? AND c.unread > 0",
            [$u->userId]
        );
        if ((int) $m['n'] > 0) {
            $items[] = ['kind' => 'unread', 'count' => (int) $m['n'], 'href' => '/?aba=mine',
                'title' => $plural((int) $m['n'], '1 cliente seu respondeu', '# clientes seus responderam'),
                'text' => $plural((int) $m['msgs'], '1 mensagem nova nos seus atendimentos.', '# mensagens novas nos seus atendimentos.')];
        }

        $sectors = array_map('intval', $u->sectors());
        $scope = $u->supervisor ? '1=1' : '(s.created_by = ' . $u->userId . ($sectors ? ' OR s.sector_id IN (' . implode(',', $sectors) . ')' : '') . ')';
        $f = (int) $this->db->one(
            "SELECT COUNT(*) n FROM talk_schedules s WHERE s.status = 'failed' AND COALESCE(s.updated_at, s.send_at) >= NOW() - INTERVAL 7 DAY AND $scope"
        )['n'];
        if ($f > 0) {
            $items[] = ['kind' => 'schedules', 'count' => $f, 'href' => '/agendamentos?aba=failed',
                'title' => $plural($f, '1 agendamento não foi enviado', '# agendamentos não foram enviados'),
                'text' => 'Veja o motivo e agende de novo.'];
        }

        if ($u->supervisor) {
            $down = $this->db->all(
                "SELECT name FROM talk_channels WHERE status = 'active' AND connection_state = 'disconnected' ORDER BY name"
            );
            if ($down) {
                $items[] = ['kind' => 'channels', 'count' => count($down), 'href' => '/conexoes',
                    'title' => $plural(count($down), '1 número de WhatsApp desconectado', '# números de WhatsApp desconectados'),
                    'text' => implode(', ', array_column($down, 'name')) . '. Leia o QR Code de novo.'];
            }
        }

        return ['count' => array_sum(array_column($items, 'count')), 'items' => $items];
    }

    private function duration(int $min): string
    {
        if ($min < 60) {
            return $min === 1 ? '1 minuto' : "$min minutos";
        }
        $h = intdiv($min, 60);
        return $h === 1 ? '1 hora' : ($h < 48 ? "$h horas" : intdiv($h, 24) . ' dias');
    }
}
