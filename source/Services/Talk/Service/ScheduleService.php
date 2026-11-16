<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;
use Source\Services\Talk\Support\Phone;

/**
 * Agendamentos: mensagem marcada para um dia e hora, mandada pelo worker (runDue) na hora certa.
 * - Sai pelo número da conversa de origem ou pelo número padrão. A API oficial da Meta só aceita
 *   modelos aprovados fora da janela de 24 h, então agendamento livre é só nos números de QR Code.
 * - Se o contato tiver conversa aberta naquele número, a mensagem entra nela; senão abre uma
 *   conversa nova (com protocolo e chamado) com quem agendou, no setor escolhido.
 * - Quem vê: supervisor tudo; os demais, os agendamentos dos próprios setores.
 */
final class ScheduleService
{
    public const MAX_BODY = 4096;

    public function __construct(private Db $db, private AccessPolicy $policy, private ConversationService $conversations)
    {
    }

    public function list(StaffMember $u, array $f = []): array
    {
        [$where, $params] = $this->scope($u);
        $status = (string) ($f['status'] ?? 'scheduled');
        if ($status === 'scheduled') {
            $where .= " AND s.status IN ('scheduled','sending')";
        } elseif (in_array($status, ['sent', 'canceled', 'failed'], true)) {
            $where .= ' AND s.status = :st';
            $params['st'] = $status;
        }
        if (!empty($f['contact_id'])) {
            $where .= ' AND s.contact_id = :cid';
            $params['cid'] = (int) $f['contact_id'];
        }
        $order = $status === 'scheduled' ? 's.send_at, s.id' : 'COALESCE(s.sent_at, s.updated_at, s.send_at) DESC, s.id DESC';
        $rows = $this->db->all(
            "SELECT s.id, s.contact_id, ct.name AS contact_name, ct.phone, s.sector_id, sec.name AS sector_name, s.channel_id, ch.name AS channel_name,
                    s.body, s.sign, s.send_at, s.status, s.created_by, u.name AS created_by_name, s.created_at, s.sent_at,
                    s.sent_conversation_id, s.origin_conversation_id, s.error, (ct.blocked_at IS NOT NULL) AS contact_blocked
               FROM talk_schedules s
               JOIN talk_contacts ct ON ct.id = s.contact_id
               JOIN talk_sectors sec ON sec.id = s.sector_id
               JOIN talk_channels ch ON ch.id = s.channel_id
               LEFT JOIN talk_users u ON u.id = s.created_by
              WHERE $where ORDER BY $order LIMIT 300",
            $params
        );
        return array_map(fn ($r) => $this->shape($u, $r), $rows);
    }

    /** Contagem por situação, para as abas. */
    public function counts(StaffMember $u): array
    {
        [$where, $params] = $this->scope($u);
        $out = ['scheduled' => 0, 'sent' => 0, 'canceled' => 0, 'failed' => 0, 'today' => 0];
        foreach ($this->db->all("SELECT s.status, COUNT(*) n, SUM(s.status = 'scheduled' AND s.send_at < CURDATE() + INTERVAL 1 DAY) today FROM talk_schedules s WHERE $where GROUP BY s.status", $params) as $r) {
            $k = $r['status'] === 'sending' ? 'scheduled' : $r['status'];
            $out[$k] += (int) $r['n'];
            $out['today'] += (int) $r['today'];
        }
        return $out;
    }

    /**
     * $in: body, send_at ("2026-11-20 09:00" ou "2026-11-20T09:00"), sign, e um destes:
     * conversation_id (agenda a partir de uma conversa) | contact_id | phone (+ name opcional);
     * sector_id quando não vem de uma conversa.
     */
    public function save(StaffMember $u, array $in, ?int $id = null): array
    {
        $old = $id !== null ? $this->editable($u, $id) : null;
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            throw new DomainError('Mensagem: obrigatória, até ' . self::MAX_BODY . ' caracteres.');
        }
        $sendAt = $this->parseDate((string) ($in['send_at'] ?? ''));
        $sign = !array_key_exists('sign', $in) || filter_var($in['sign'], FILTER_VALIDATE_BOOL);

        if ($old !== null) {
            // editar: só texto, hora e assinatura (destino e setor ficam)
            $this->db->run("UPDATE talk_schedules SET body = ?, send_at = ?, sign = ?, updated_at = NOW() WHERE id = ? AND status = 'scheduled'",
                [$body, $sendAt, (int) $sign, $id]);
            return $this->one($u, $id);
        }

        $origin = null;
        if (!empty($in['conversation_id'])) {
            $origin = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [(int) $in['conversation_id']]);
            if (!$origin || !$this->policy->canAct($u, $origin)) {
                throw new DomainError('Você não tem acesso a esta conversa.', 403);
            }
            $contactId = (int) $origin['contact_id'];
            $sector = $origin['sector_id'] !== null ? (int) $origin['sector_id'] : null;
            $channelId = (int) $origin['channel_id'];
        } else {
            $contactId = $this->contactFor($u, $in);
            $sector = isset($in['sector_id']) && $in['sector_id'] !== '' ? (int) $in['sector_id'] : null;
            $channelId = null;
        }
        $sector ??= isset($in['sector_id']) && $in['sector_id'] !== '' ? (int) $in['sector_id'] : null;
        if ($sector === null || !$this->db->one('SELECT id FROM talk_sectors WHERE id = ? AND active = 1', [$sector])) {
            throw new DomainError('Escolha o setor.');
        }
        if (!$u->supervisor && !in_array($sector, $u->sectors(), true)) {
            throw new DomainError('Você só agenda mensagens dos seus setores.', 403);
        }
        $channelId = $this->channelFor($channelId);
        if ($this->db->one('SELECT id FROM talk_contacts WHERE id = ? AND blocked_at IS NOT NULL', [$contactId])) {
            throw new DomainError('Este contato está bloqueado.');
        }

        $id = $this->db->insert('talk_schedules', [
            'contact_id' => $contactId, 'channel_id' => $channelId, 'sector_id' => $sector,
            'origin_conversation_id' => $origin['id'] ?? null, 'body' => $body, 'sign' => (int) $sign,
            'send_at' => $sendAt, 'created_by' => $u->userId,
        ]);
        if ($origin) {
            $this->conversations->addNote((int) $origin['id'], $u, 'Mensagem agendada para ' . date('d/m/Y \à\s H:i', strtotime($sendAt)) . ': "' . mb_strimwidth($body, 0, 120, '…') . '"');
        }
        return $this->one($u, $id);
    }

    public function cancel(StaffMember $u, int $id): array
    {
        $this->editable($u, $id);
        $this->db->run("UPDATE talk_schedules SET status = 'canceled', canceled_by = ?, updated_at = NOW() WHERE id = ? AND status = 'scheduled'", [$u->userId, $id]);
        return $this->one($u, $id);
    }

    /** Worker: manda o que venceu. Devolve quantas foram enviadas para a fila de saída. */
    public function runDue(int $limit = 20): int
    {
        $sent = 0;
        $ids = array_column($this->db->all("SELECT id FROM talk_schedules WHERE status = 'scheduled' AND send_at <= NOW() ORDER BY send_at, id LIMIT " . max(1, $limit)), 'id');
        foreach ($ids as $sid) {
            try {
                $sent += (int) $this->db->transaction(fn (): bool => $this->sendOne((int) $sid));
            } catch (\Throwable $e) {
                $this->db->run("UPDATE talk_schedules SET status = 'failed', error = ?, updated_at = NOW() WHERE id = ? AND status IN ('scheduled','sending')",
                    [mb_substr($e->getMessage(), 0, 255), $sid]);
                error_log("[talk] agendamento $sid: " . $e->getMessage());
            }
        }
        return $sent;
    }

    private function sendOne(int $sid): bool
    {
        $s = $this->db->one("SELECT * FROM talk_schedules WHERE id = ? AND status = 'scheduled' FOR UPDATE", [$sid]);
        if (!$s) {
            return false; // outro worker pegou ou foi cancelado
        }
        $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$s['contact_id']]);
        if ($contact['blocked_at'] !== null) {
            $this->db->run("UPDATE talk_schedules SET status = 'canceled', error = 'Contato bloqueado antes do envio.', updated_at = NOW() WHERE id = ?", [$sid]);
            return false;
        }
        $ch = $this->db->one('SELECT status FROM talk_channels WHERE id = ?', [$s['channel_id']]);
        if (($ch['status'] ?? '') === 'disabled') {
            throw new DomainError('O número de envio está desativado.');
        }
        $conv = $this->db->one(
            "SELECT * FROM talk_conversations WHERE contact_id = ? AND channel_id = ? AND status <> 'closed' ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$s['contact_id'], $s['channel_id']]
        );
        $author = $this->db->one('SELECT name FROM talk_users WHERE id = ?', [$s['created_by']]);
        if ($conv === null) {
            $conv = $this->conversations->startOutbound((int) $s['channel_id'], $contact, (int) $s['sector_id'], (int) $s['created_by'],
                'Conversa iniciada por mensagem agendada por ' . explode(' (', (string) ($author['name'] ?? '—'))[0] . '.');
        }
        $first = explode(' (', (string) ($author['name'] ?? ''))[0];
        $body = (int) $s['sign'] === 1 && $first !== '' ? "*{$first}:*\n{$s['body']}" : $s['body'];
        $msgId = $this->conversations->queueOutbound($conv, $body, (int) $s['created_by']);
        $this->db->run("UPDATE talk_schedules SET status = 'sent', sent_at = NOW(), sent_conversation_id = ?, sent_message_id = ?, updated_at = NOW() WHERE id = ?",
            [$conv['id'], $msgId, $sid]);
        return true;
    }

    public function one(StaffMember $u, int $id): array
    {
        [$where, $params] = $this->scope($u);
        foreach ($this->db->all('SELECT s.status FROM talk_schedules s WHERE s.id = :id AND ' . $where, $params + ['id' => $id]) as $r) {
            foreach ($this->list($u, ['status' => in_array($r['status'], ['scheduled', 'sending'], true) ? 'scheduled' : $r['status']]) as $row) {
                if ($row['id'] === $id) {
                    return $row;
                }
            }
        }
        throw new DomainError('Agendamento não encontrado.', 404);
    }

    private function editable(StaffMember $u, int $id): array
    {
        $row = $this->db->one('SELECT * FROM talk_schedules WHERE id = ?', [$id]);
        [$where, $params] = $this->scope($u);
        if (!$row || !$this->db->one('SELECT s.id FROM talk_schedules s WHERE s.id = :id AND ' . $where, $params + ['id' => $id])) {
            throw new DomainError('Agendamento não encontrado.', 404);
        }
        if ($row['status'] !== 'scheduled') {
            throw new DomainError('Este agendamento já foi enviado ou cancelado.');
        }
        if (!$this->canChange($u, $row)) {
            throw new DomainError('Só quem agendou, o gerente do setor ou o supervisor alteram este agendamento.', 403);
        }
        return $row;
    }

    private function canChange(StaffMember $u, array $row): bool
    {
        return $u->supervisor || (int) $row['created_by'] === $u->userId || in_array((int) $row['sector_id'], $u->managerSectors, true);
    }

    private function contactFor(StaffMember $u, array $in): int
    {
        if (!empty($in['contact_id'])) {
            $c = $this->db->one('SELECT id FROM talk_contacts WHERE id = ?', [(int) $in['contact_id']]);
            if (!$c) {
                throw new DomainError('Contato não encontrado.', 404);
            }
            return (int) $c['id'];
        }
        $digits = preg_replace('/\D+/', '', (string) ($in['phone'] ?? ''));
        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw new DomainError('Informe o contato ou um telefone com DDD.');
        }
        $phone = Phone::normalize($digits);
        $c = $this->db->one('SELECT id FROM talk_contacts WHERE phone = ?', [$phone]);
        if ($c) {
            return (int) $c['id'];
        }
        $name = trim((string) ($in['name'] ?? '')) ?: null;
        return $this->db->insert('talk_contacts', ['phone' => $phone, 'name' => $name !== null ? mb_substr($name, 0, 150) : null]);
    }

    /** Número de envio: o da conversa (se for QR Code) ou o padrão; nunca a API oficial. */
    private function channelFor(?int $fromConversation): int
    {
        if ($fromConversation !== null) {
            $ch = $this->db->one("SELECT id, kind, status FROM talk_channels WHERE id = ?", [$fromConversation]);
            if ($ch && $ch['kind'] !== 'official' && $ch['status'] === 'active') {
                return (int) $ch['id'];
            }
        }
        $ch = $this->db->one("SELECT id FROM talk_channels WHERE status = 'active' AND kind <> 'official' AND purpose IN ('attendance','both') ORDER BY is_default DESC, id LIMIT 1");
        if (!$ch) {
            throw new DomainError('Nenhum número de QR Code ativo para mandar a mensagem. A API oficial só manda modelos aprovados (envio em massa).');
        }
        return (int) $ch['id'];
    }

    private function parseDate(string $raw): string
    {
        $raw = str_replace('T', ' ', trim($raw));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($raw, 0, 16));
        if ($d === false || $d->format('Y-m-d H:i') !== substr($raw, 0, 16)) {
            throw new DomainError('Data e hora inválidas.');
        }
        $now = (int) $this->db->one('SELECT UNIX_TIMESTAMP(NOW()) n')['n'];
        $ts = (int) $this->db->one('SELECT UNIX_TIMESTAMP(?) n', [$d->format('Y-m-d H:i:00')])['n'];
        if ($ts < $now + 60) {
            throw new DomainError('Escolha um horário a partir de daqui a 1 minuto.');
        }
        if ($ts > $now + 366 * 86400) {
            throw new DomainError('Agende para no máximo um ano.');
        }
        return $d->format('Y-m-d H:i:00');
    }

    /** @return array{0:string, 1:array} */
    private function scope(StaffMember $u): array
    {
        if ($u->supervisor) {
            return ['1=1', []];
        }
        $sectors = array_map('intval', $u->sectors());
        return ['(s.created_by = :me' . ($sectors ? ' OR s.sector_id IN (' . implode(',', $sectors) . ')' : '') . ')', ['me' => $u->userId]];
    }

    private function shape(StaffMember $u, array $r): array
    {
        return [
            'id' => (int) $r['id'], 'contact_id' => (int) $r['contact_id'], 'contact_name' => $r['contact_name'], 'phone' => $r['phone'],
            'sector_id' => (int) $r['sector_id'], 'sector_name' => $r['sector_name'], 'channel_name' => $r['channel_name'],
            'body' => $r['body'], 'sign' => (bool) $r['sign'], 'send_at' => $r['send_at'], 'status' => $r['status'],
            'created_by' => (int) $r['created_by'], 'created_by_name' => $r['created_by_name'], 'created_at' => $r['created_at'],
            'sent_at' => $r['sent_at'], 'conversation_id' => $r['sent_conversation_id'] !== null ? (int) $r['sent_conversation_id'] : ($r['origin_conversation_id'] !== null ? (int) $r['origin_conversation_id'] : null),
            'error' => $r['error'], 'contact_blocked' => (bool) $r['contact_blocked'],
            'can_edit' => $r['status'] === 'scheduled' && $this->canChange($u, $r),
        ];
    }
}
