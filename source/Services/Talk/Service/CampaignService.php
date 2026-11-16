<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Gateway\CloudApiGateway;
use Source\Services\Talk\Gateway\GatewayFactory;
use Source\Services\Talk\Support\Db;
use Source\Services\Talk\Support\Phone;

/**
 * Envio em massa pela API oficial da Meta, sempre com modelo aprovado (a Meta não deixa mandar texto
 * livre para quem não escreveu nas últimas 24 h).
 * - Público: contatos de uma etiqueta ou lista colada (telefone; nome; colunas extras).
 * - Variáveis do modelo ({{1}}, {{2}}…): primeiro nome, nome, coluna da lista ou texto fixo.
 * - Fica de fora: bloqueados, quem pediu para sair, telefone inválido, variável vazia, repetidos.
 * - Envio em ritmo controlado pelo worker (por minuto); pausar, retomar e cancelar.
 * - Quem cria: supervisor (qualquer setor) e gerentes (dos setores que gerenciam).
 */
final class CampaignService
{
    public const MAX_RECIPIENTS = 5000;
    private const SOURCES = ['first_name', 'name', 'phone'];

    public function __construct(private Db $db, private GatewayFactory $gateways, private bool $lab = false)
    {
    }

    // ------------------------------------------------------------------ modelos

    public function templates(StaffMember $u): array
    {
        $this->assertCanUse($u);
        return array_map(fn ($t) => array_merge($t, ['id' => (int) $t['id'], 'channel_id' => (int) $t['channel_id'], 'variables' => (int) $t['variables'], 'used' => (int) $t['used']]), $this->db->all(
            'SELECT t.id, t.channel_id, ch.name AS channel_name, t.name, t.language, t.category, t.body, t.variables, t.status, t.synced_at,
                    (SELECT COUNT(*) FROM talk_campaigns c WHERE c.template_id = t.id) AS used
               FROM talk_templates t JOIN talk_channels ch ON ch.id = t.channel_id ORDER BY t.status = \'APPROVED\' DESC, t.name'
        ));
    }

    /** Cadastro manual (o nome e o idioma têm de ser iguais aos do modelo aprovado na Meta). */
    public function saveTemplate(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertSupervisor($u);
        $channel = (int) ($in['channel_id'] ?? 0);
        $this->bulkChannel($channel);
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        $lang = trim((string) ($in['language'] ?? 'pt_BR')) ?: 'pt_BR';
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
            throw new DomainError('Nome do modelo: igual ao da Meta, só letras minúsculas, números e "_".');
        }
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)) {
            throw new DomainError('Idioma inválido (ex.: pt_BR).');
        }
        if ($body === '' || mb_strlen($body) > 1024) {
            throw new DomainError('Texto do modelo: obrigatório, até 1024 caracteres.');
        }
        if ($this->db->one('SELECT id FROM talk_templates WHERE channel_id = ? AND name = ? AND language = ? AND id <> ?', [$channel, $name, $lang, $id ?? 0])) {
            throw new DomainError('Este modelo já está cadastrado.');
        }
        $row = ['channel_id' => $channel, 'name' => $name, 'language' => $lang, 'category' => ($in['category'] ?? null) ?: null,
            'body' => $body, 'variables' => $this->countVars($body)];
        if ($id === null) {
            $id = $this->db->insert('talk_templates', $row);
        } else {
            $this->db->run('UPDATE talk_templates SET channel_id = ?, name = ?, language = ?, category = ?, body = ?, variables = ? WHERE id = ?',
                [$channel, $name, $lang, $row['category'], $body, $row['variables'], $id]);
        }
        return $this->template($id);
    }

    public function deleteTemplate(StaffMember $u, int $id): void
    {
        $this->assertSupervisor($u);
        if ($this->db->one('SELECT id FROM talk_campaigns WHERE template_id = ? LIMIT 1', [$id])) {
            throw new DomainError('Este modelo já foi usado em envios; não dá para excluir.');
        }
        $this->db->run('DELETE FROM talk_templates WHERE id = ?', [$id]);
    }

    /** Busca os modelos na conta da Meta e atualiza a lista (novos, texto e situação). */
    public function syncTemplates(StaffMember $u, int $channelId): array
    {
        $this->assertSupervisor($u);
        $this->bulkChannel($channelId);
        $gw = $this->gateways->forChannel($channelId);
        if (!$gw instanceof CloudApiGateway) {
            throw new DomainError('Só números da API oficial têm modelos na Meta.');
        }
        try {
            $list = $gw->listTemplates();
        } catch (\Source\Services\Talk\Gateway\GatewayException $e) {
            throw new DomainError($e->getMessage(), 502);
        }
        $n = 0;
        foreach ($list as $t) {
            $this->db->run(
                'INSERT INTO talk_templates (channel_id, name, language, category, body, variables, status, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE category = VALUES(category), body = VALUES(body), variables = VALUES(variables), status = VALUES(status), synced_at = NOW()',
                [$channelId, $t['name'], $t['language'], $t['category'], $t['body'], $this->countVars($t['body']), $t['status']]
            );
            $n++;
        }
        return ['synced' => $n];
    }

    // ------------------------------------------------------------------ campanhas

    public function list(StaffMember $u): array
    {
        $this->assertCanUse($u);
        $where = $u->supervisor ? '1=1' : ($u->managerSectors ? 'c.sector_id IN (' . implode(',', array_map('intval', $u->managerSectors)) . ')' : '1=0');
        $rows = $this->db->all(
            "SELECT c.id, c.name, c.status, c.send_at, c.created_at, c.started_at, c.finished_at, c.per_minute, c.sector_id, s.name AS sector_name,
                    c.channel_id, ch.name AS channel_name, c.template_id, t.name AS template_name, t.body AS template_body, c.var_map, c.audience,
                    u.name AS created_by_name,
                    COUNT(r.id) AS total,
                    SUM(r.status = 'queued') AS queued, SUM(r.status IN ('sent','delivered','read')) AS sent,
                    SUM(r.status IN ('delivered','read')) AS delivered, SUM(r.status = 'read') AS seen,
                    SUM(r.status = 'failed') AS failed, SUM(r.status = 'skipped') AS skipped, SUM(r.replied_at IS NOT NULL) AS replied
               FROM talk_campaigns c
               JOIN talk_sectors s ON s.id = c.sector_id
               JOIN talk_channels ch ON ch.id = c.channel_id
               JOIN talk_templates t ON t.id = c.template_id
               LEFT JOIN talk_users u ON u.id = c.created_by
               LEFT JOIN talk_campaign_recipients r ON r.campaign_id = c.id
              WHERE $where
              GROUP BY c.id, c.name, c.status, c.send_at, c.created_at, c.started_at, c.finished_at, c.per_minute, c.sector_id, s.name,
                       c.channel_id, ch.name, c.template_id, t.name, t.body, c.var_map, c.audience, u.name
              ORDER BY FIELD(c.status, 'running', 'scheduled', 'paused', 'draft', 'done', 'canceled'), c.id DESC"
        );
        return array_map(function ($r) {
            foreach (['id', 'per_minute', 'sector_id', 'channel_id', 'template_id', 'total', 'queued', 'sent', 'delivered', 'seen', 'failed', 'skipped', 'replied'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['var_map'] = json_decode((string) $r['var_map'], true) ?: [];
            $aud = json_decode((string) $r['audience'], true) ?: [];
            $r['audience'] = ['type' => $aud['type'] ?? 'tag', 'tag_id' => $aud['tag_id'] ?? null, 'rows' => $aud['rows'] ?? ''];
            return $r;
        }, $rows);
    }

    public function one(StaffMember $u, int $id): array
    {
        foreach ($this->list($u) as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        throw new DomainError('Envio não encontrado.', 404);
    }

    /** Rascunho: grava sem montar a lista ainda. */
    public function save(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertCanUse($u);
        if ($id !== null) {
            $old = $this->one($u, $id);
            if ($old['status'] !== 'draft') {
                throw new DomainError('Só dá para editar um envio que ainda não começou.');
            }
        }
        [$row] = $this->validate($u, $in);
        if ($id === null) {
            $id = $this->db->insert('talk_campaigns', $row + ['created_by' => $u->userId]);
        } else {
            $this->db->run('UPDATE talk_campaigns SET name = ?, channel_id = ?, template_id = ?, sector_id = ?, var_map = ?, audience = ?, send_at = ?, per_minute = ? WHERE id = ?',
                [$row['name'], $row['channel_id'], $row['template_id'], $row['sector_id'], $row['var_map'], $row['audience'], $row['send_at'], $row['per_minute'], $id]);
        }
        return $this->one($u, $id);
    }

    /** Prévia: quantos vão receber, quantos ficam de fora (e por quê) e 3 exemplos do texto. */
    public function preview(StaffMember $u, array $in): array
    {
        $this->assertCanUse($u);
        [$row, $tpl] = $this->validate($u, $in, forPreview: true);
        $recipients = $this->buildRecipients($row, $tpl);
        $ok = array_values(array_filter($recipients, fn ($r) => $r['skip'] === null));
        $why = [];
        foreach ($recipients as $r) {
            if ($r['skip'] !== null) {
                $why[$r['skip']] = ($why[$r['skip']] ?? 0) + 1;
            }
        }
        return [
            'total' => count($ok),
            'skipped' => $why,
            'samples' => array_map(fn ($r) => ['name' => $r['name'], 'phone' => $r['phone'], 'text' => CampaignReplies::render($tpl['body'], $r['params'])], array_slice($ok, 0, 3)),
            'minutes' => (int) ceil(count($ok) / max(1, $row['per_minute'])),
        ];
    }

    /** Monta a lista de destinatários e põe para enviar (agora ou na data marcada). */
    public function start(StaffMember $u, int $id): array
    {
        $c = $this->one($u, $id);
        if ($c['status'] !== 'draft') {
            throw new DomainError('Este envio já foi iniciado.');
        }
        $in = ['name' => $c['name'], 'channel_id' => $c['channel_id'], 'template_id' => $c['template_id'], 'sector_id' => $c['sector_id'],
            'var_map' => $c['var_map'], 'audience' => $c['audience'], 'send_at' => $c['send_at'], 'per_minute' => $c['per_minute']];
        [$row, $tpl] = $this->validate($u, $in, forStart: true);
        $recipients = $this->buildRecipients($row, $tpl);
        if (!array_filter($recipients, fn ($r) => $r['skip'] === null)) {
            throw new DomainError('Ninguém para receber: confira o público e as variáveis.');
        }
        $this->db->transaction(function () use ($id, $recipients, $row): void {
            foreach ($recipients as $r) {
                $this->db->insert('talk_campaign_recipients', [
                    'campaign_id' => $id, 'contact_id' => $r['contact_id'], 'phone' => $r['phone'], 'name' => $r['name'] !== null ? mb_substr($r['name'], 0, 150) : null,
                    'params' => json_encode($r['params'], JSON_UNESCAPED_UNICODE),
                    'status' => $r['skip'] === null ? 'queued' : 'skipped', 'skip_reason' => $r['skip'],
                ]);
            }
            $later = $row['send_at'] !== null && strtotime($row['send_at']) > (int) $this->db->one('SELECT UNIX_TIMESTAMP(NOW()) n')['n'];
            $this->db->run("UPDATE talk_campaigns SET status = ?, started_at = IF(? = 'running', NOW(), NULL) WHERE id = ?",
                [$later ? 'scheduled' : 'running', $later ? 'scheduled' : 'running', $id]);
        });
        return $this->one($u, $id);
    }

    public function pause(StaffMember $u, int $id): array
    {
        return $this->transition($u, $id, ['running', 'scheduled'], 'paused');
    }

    public function resume(StaffMember $u, int $id): array
    {
        return $this->transition($u, $id, ['paused'], 'running');
    }

    /** Cancelar: quem ainda não recebeu não recebe mais. */
    public function cancel(StaffMember $u, int $id): array
    {
        $c = $this->transition($u, $id, ['draft', 'scheduled', 'running', 'paused'], 'canceled');
        $this->db->run("UPDATE talk_campaign_recipients SET status = 'skipped', skip_reason = 'envio cancelado' WHERE campaign_id = ? AND status = 'queued'", [$id]);
        $this->db->run('UPDATE talk_campaigns SET finished_at = NOW() WHERE id = ?', [$id]);
        return $this->one($u, $id);
    }

    public function recipients(StaffMember $u, int $id, string $status = ''): array
    {
        $this->one($u, $id);
        $sql = 'SELECT id, phone, name, status, skip_reason, error, sent_at, replied_at FROM talk_campaign_recipients WHERE campaign_id = ?';
        $p = [$id];
        if ($status === 'replied') {
            $sql .= ' AND replied_at IS NOT NULL';
        } elseif ($status !== '') {
            $sql .= ' AND status = ?';
            $p[] = $status;
        }
        return $this->db->all($sql . ' ORDER BY id LIMIT 500', $p);
    }

    // ------------------------------------------------------------------ worker

    /** Envia a próxima leva de cada envio em andamento, respeitando o ritmo por minuto. */
    public function runDue(int $maxPerCall = 30): int
    {
        $this->db->run("UPDATE talk_campaigns SET status = 'running', started_at = NOW() WHERE status = 'scheduled' AND send_at <= NOW()");
        $sent = 0;
        foreach ($this->db->all("SELECT c.id, c.per_minute, c.channel_id, c.last_batch_at, t.name AS template, t.language, t.body,
                                         TIMESTAMPDIFF(SECOND, COALESCE(c.last_batch_at, c.started_at, NOW() - INTERVAL 60 SECOND), NOW()) AS since
                                    FROM talk_campaigns c JOIN talk_templates t ON t.id = c.template_id WHERE c.status = 'running'") as $c) {
            // primeira leva sai já (1/12 do ritmo, mínimo 1); depois, proporcional ao tempo desde a última
            $budget = $c['last_batch_at'] === null
                ? min($maxPerCall, max(1, (int) ceil((int) $c['per_minute'] / 12)))
                : min($maxPerCall, (int) floor((int) $c['per_minute'] * (int) $c['since'] / 60));
            if ($budget < 1) {
                continue;
            }
            $gw = $this->gateways->forChannel((int) $c['channel_id']);
            $batch = $this->db->all("SELECT r.id, r.phone, r.params FROM talk_campaign_recipients r WHERE r.campaign_id = ? AND r.status = 'queued' ORDER BY r.id LIMIT $budget", [$c['id']]);
            foreach ($batch as $r) {
                $contact = $this->db->one('SELECT blocked_at, opt_out_at FROM talk_contacts WHERE phone = ?', [$r['phone']]);
                if ($contact && ($contact['blocked_at'] !== null || $contact['opt_out_at'] !== null)) {
                    $this->db->run("UPDATE talk_campaign_recipients SET status = 'skipped', skip_reason = ? WHERE id = ?",
                        [$contact['blocked_at'] !== null ? 'bloqueado' : 'pediu para sair', $r['id']]);
                    continue;
                }
                $params = json_decode((string) $r['params'], true) ?: [];
                try {
                    $res = $gw->sendTemplate($r['phone'], $c['template'], $c['language'], $params, CampaignReplies::render($c['body'], $params));
                    $this->db->run("UPDATE talk_campaign_recipients SET status = 'sent', provider_message_id = ?, sent_at = NOW() WHERE id = ?", [$res->providerMessageId, $r['id']]);
                    $sent++;
                } catch (\Throwable $e) {
                    $this->db->run("UPDATE talk_campaign_recipients SET status = 'failed', error = ?, sent_at = NOW() WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $r['id']]);
                }
            }
            $this->db->run('UPDATE talk_campaigns SET last_batch_at = NOW() WHERE id = ?', [$c['id']]);
            if (!$this->db->one("SELECT id FROM talk_campaign_recipients WHERE campaign_id = ? AND status = 'queued' LIMIT 1", [$c['id']])) {
                $this->db->run("UPDATE talk_campaigns SET status = 'done', finished_at = NOW() WHERE id = ? AND status = 'running'", [$c['id']]);
            }
        }
        return $sent;
    }

    // ------------------------------------------------------------------ apoio

    /** @return array{0:array, 1:array} linha da campanha e o modelo */
    private function validate(StaffMember $u, array $in, bool $forPreview = false, bool $forStart = false): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (!$forPreview && ($name === '' || mb_strlen($name) > 120)) {
            throw new DomainError('Dê um nome ao envio (até 120 caracteres).');
        }
        $channel = (int) ($in['channel_id'] ?? 0);
        $this->bulkChannel($channel);
        $tpl = $this->db->one('SELECT * FROM talk_templates WHERE id = ? AND channel_id = ?', [(int) ($in['template_id'] ?? 0), $channel]);
        if (!$tpl) {
            throw new DomainError('Escolha um modelo deste número.');
        }
        if ($tpl['status'] !== 'APPROVED') {
            throw new DomainError('Este modelo não está aprovado na Meta.');
        }
        $sector = (int) ($in['sector_id'] ?? 0);
        if (!$this->db->one('SELECT id FROM talk_sectors WHERE id = ? AND active = 1', [$sector])) {
            throw new DomainError('Escolha o setor que recebe as respostas.');
        }
        if (!$u->supervisor && !in_array($sector, $u->managerSectors, true)) {
            throw new DomainError('Você só faz envios dos setores que gerencia.', 403);
        }
        $map = array_map('strval', (array) ($in['var_map'] ?? []));
        for ($i = 1; $i <= (int) $tpl['variables']; $i++) {
            $src = $map[(string) $i] ?? '';
            if (!in_array($src, self::SOURCES, true) && !preg_match('/^col:[3-9]$/', $src) && !preg_match('/^fixed:.{1,200}$/su', $src)) {
                throw new DomainError("Diga de onde vem a variável {{{$i}}}.");
            }
        }
        $aud = (array) ($in['audience'] ?? []);
        $type = (string) ($aud['type'] ?? '');
        if ($type === 'tag') {
            if (!$this->db->one('SELECT id FROM talk_tags WHERE id = ?', [(int) ($aud['tag_id'] ?? 0)])) {
                throw new DomainError('Escolha a etiqueta.');
            }
            foreach ($map as $src) {
                if (str_starts_with($src, 'col:')) {
                    throw new DomainError('Colunas só existem quando o público é uma lista colada.');
                }
            }
            $aud = ['type' => 'tag', 'tag_id' => (int) $aud['tag_id']];
        } elseif ($type === 'list') {
            $rows = trim((string) ($aud['rows'] ?? ''));
            if ($rows === '') {
                throw new DomainError('Cole a lista de telefones.');
            }
            $aud = ['type' => 'list', 'rows' => mb_substr($rows, 0, 400000)];
        } else {
            throw new DomainError('Escolha o público: etiqueta ou lista.');
        }
        $sendAt = null;
        $raw = str_replace('T', ' ', trim((string) ($in['send_at'] ?? '')));
        if ($raw !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($raw, 0, 16));
            if (!$d) {
                throw new DomainError('Data e hora inválidas.');
            }
            $sendAt = $d->format('Y-m-d H:i:00');
        }
        $perMinute = max(1, min(600, (int) ($in['per_minute'] ?? 60)));
        return [[
            'name' => $name, 'channel_id' => $channel, 'template_id' => (int) $tpl['id'], 'sector_id' => $sector,
            'var_map' => json_encode($map, JSON_UNESCAPED_UNICODE), 'audience' => json_encode($aud, JSON_UNESCAPED_UNICODE),
            'send_at' => $sendAt, 'per_minute' => $perMinute,
        ], $tpl];
    }

    /** @return list<array{phone:string, name:?string, contact_id:?int, params:list<string>, skip:?string}> */
    private function buildRecipients(array $row, array $tpl): array
    {
        $aud = json_decode($row['audience'], true);
        $map = json_decode($row['var_map'], true) ?: [];
        $people = [];
        if ($aud['type'] === 'tag') {
            foreach ($this->db->all('SELECT c.id, c.phone, c.name FROM talk_contacts c JOIN talk_contact_tags ct ON ct.contact_id = c.id WHERE ct.tag_id = ? ORDER BY c.id', [$aud['tag_id']]) as $c) {
                $people[] = ['phone' => $c['phone'], 'name' => $c['name'], 'cols' => []];
            }
        } else {
            $first = true;
            foreach (preg_split('/\R/u', $aud['rows']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $isFirst = $first;
                $first = false;
                $cols = array_map('trim', preg_split('/\t|;|,(?=(?:[^"]*"[^"]*")*[^"]*$)/u', $line));
                $cols = array_map(fn ($v) => trim($v, " \"'"), $cols);
                if ($isFirst && !preg_match('/\d{6}/', preg_replace('/\D/', '', $cols[0]) ?? '')) {
                    continue; // primeira linha sem telefone = cabeçalho
                }
                $people[] = ['phone' => $cols[0], 'name' => ($cols[1] ?? '') ?: null, 'cols' => $cols];
            }
        }
        $out = [];
        $seen = [];
        foreach ($people as $p) {
            $digits = preg_replace('/\D+/', '', $p['phone']);
            $phone = strlen($digits) >= 10 && strlen($digits) <= 13 ? Phone::normalize($digits) : null;
            $skip = null;
            $contact = $phone ? $this->db->one('SELECT id, name, blocked_at, opt_out_at FROM talk_contacts WHERE phone = ?', [$phone]) : null;
            $name = $p['name'] ?? $contact['name'] ?? null;
            if ($phone === null || !preg_match('/^55\d{10,11}$|^(?!55)\d{10,13}$/', $phone)) {
                $skip = 'telefone inválido';
            } elseif (isset($seen[$phone])) {
                continue; // repetido na lista: manda uma vez só
            } elseif ($contact && $contact['blocked_at'] !== null) {
                $skip = 'bloqueado';
            } elseif ($contact && $contact['opt_out_at'] !== null) {
                $skip = 'pediu para sair';
            }
            $params = [];
            for ($i = 1; $i <= (int) $tpl['variables']; $i++) {
                $src = (string) ($map[(string) $i] ?? '');
                $v = match (true) {
                    $src === 'first_name' => $name ? explode(' ', trim(preg_replace('/\(.*\)/', '', $name)))[0] : '',
                    $src === 'name' => (string) $name,
                    $src === 'phone' => (string) $phone,
                    str_starts_with($src, 'col:') => (string) ($p['cols'][(int) substr($src, 4) - 1] ?? ''),
                    str_starts_with($src, 'fixed:') => substr($src, 6),
                    default => '',
                };
                $params[] = mb_substr(trim($v), 0, 200);
            }
            if ($skip === null && in_array('', $params, true)) {
                $skip = 'variável vazia';
            }
            if ($phone !== null) {
                $seen[$phone] = true;
            }
            $out[] = ['phone' => $phone ?? mb_substr($digits ?: $p['phone'], 0, 20), 'name' => $name, 'contact_id' => $contact ? (int) $contact['id'] : null, 'params' => $params, 'skip' => $skip];
            if (count($out) > self::MAX_RECIPIENTS) {
                throw new DomainError('No máximo ' . self::MAX_RECIPIENTS . ' pessoas por envio.');
            }
        }
        return $out;
    }

    private function transition(StaffMember $u, int $id, array $from, string $to): array
    {
        $c = $this->one($u, $id);
        if (!in_array($c['status'], $from, true)) {
            throw new DomainError('Não dá para fazer isso com um envio ' . ['draft' => 'em rascunho', 'scheduled' => 'agendado', 'running' => 'enviando',
                'paused' => 'pausado', 'done' => 'concluído', 'canceled' => 'cancelado'][$c['status']] . '.');
        }
        $this->db->run('UPDATE talk_campaigns SET status = ?, last_batch_at = IF(? = \'running\', NOW(), last_batch_at) WHERE id = ?', [$to, $to, $id]);
        return $this->one($u, $id);
    }

    /** Número de envio em massa: API oficial ativa (no laboratório, também o simulado). */
    private function bulkChannel(int $id): void
    {
        $ch = $this->db->one('SELECT kind, status FROM talk_channels WHERE id = ?', [$id]);
        if (!$ch || $ch['status'] !== 'active' || !($ch['kind'] === 'official' || ($this->lab && $ch['kind'] === 'lab'))) {
            throw new DomainError('Escolha um número ativo da API oficial para o envio em massa.');
        }
    }

    private function countVars(string $body): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    private function template(int $id): array
    {
        foreach ($this->db->all('SELECT id FROM talk_templates WHERE id = ?', [$id]) as $_) {
            foreach ($this->templatesRaw() as $t) {
                if ((int) $t['id'] === $id) {
                    return $t;
                }
            }
        }
        throw new DomainError('Modelo não encontrado.', 404);
    }

    private function templatesRaw(): array
    {
        return array_map(fn ($t) => array_merge($t, ['id' => (int) $t['id'], 'channel_id' => (int) $t['channel_id'], 'variables' => (int) $t['variables']]), $this->db->all('SELECT t.*, ch.name AS channel_name FROM talk_templates t JOIN talk_channels ch ON ch.id = t.channel_id'));
    }

    private function assertCanUse(StaffMember $u): void
    {
        if (!$u->supervisor && !$u->managerSectors) {
            throw new DomainError('Envio em massa: só supervisor e gerentes.', 403);
        }
    }

    private function assertSupervisor(StaffMember $u): void
    {
        if (!$u->supervisor) {
            throw new DomainError('Só o supervisor cadastra modelos.', 403);
        }
    }
}
