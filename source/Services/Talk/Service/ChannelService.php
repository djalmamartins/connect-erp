<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Gateway\GatewayException;
use Source\Services\Talk\Gateway\GatewayFactory;
use Source\Services\Talk\Support\Crypto;
use Source\Services\Talk\Support\Db;
use Source\Services\Talk\Support\Phone;

/**
 * Tela "Conexão WhatsApp": vários números, cada um com seu setor (opcional), QR Code e situação.
 * Só o supervisor mexe. Tokens e chaves ficam cifrados (Crypto) e nunca voltam para a tela,
 * só os 4 últimos caracteres. Campo de segredo vazio ao editar = manter o atual.
 */
final class ChannelService
{
    /** campo => é segredo? */
    private const FIELDS = [
        'qrcode' => ['base_url' => false, 'instance' => false, 'api_key' => true, 'webhook_secret' => true],
        'official' => ['phone_number_id' => false, 'business_account_id' => false, 'api_version' => false,
            'access_token' => true, 'app_secret' => true, 'verify_token' => false],
        'lab' => [],
    ];
    private const REQUIRED = [
        'qrcode' => ['instance'],
        'official' => ['phone_number_id', 'access_token', 'app_secret'],
        'lab' => [],
    ];

    public function __construct(private Db $db, private Crypto $crypto, private GatewayFactory $gateways, private string $appUrl, private bool $lab = false, private string $qrWebhookBase = '')
    {
    }

    public function list(StaffMember $u): array
    {
        $this->assertSupervisor($u);
        $rows = $this->db->all(
            "SELECT ch.id, ch.name, ch.kind, ch.purpose, ch.phone, ch.status, ch.sector_id, s.name AS sector_name, ch.is_default,
                    ch.connection_state, ch.state_checked_at, ch.last_error, ch.config_encrypted, ch.created_at,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.channel_id = ch.id) AS conversations,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.channel_id = ch.id AND c.status <> 'closed') AS active_conversations
               FROM talk_channels ch LEFT JOIN talk_sectors s ON s.id = ch.sector_id
              ORDER BY ch.status = 'disabled', ch.is_default DESC, ch.id"
        );
        $staff = $this->db->all(
            "SELECT s.sector_id, u.id, u.name FROM talk_staff s JOIN talk_users u ON u.id = s.user_id
              WHERE u.active = 1 AND s.sector_id IS NOT NULL ORDER BY u.name"
        );
        return array_map(function (array $r) use ($staff) {
            $sector = $r['sector_id'] !== null ? (int) $r['sector_id'] : null;
            $people = [];
            foreach ($staff as $s) {
                if ($sector === null || (int) $s['sector_id'] === $sector) {
                    $people[(int) $s['id']] = ['id' => (int) $s['id'], 'name' => $s['name']];
                }
            }
            return [
                'id' => (int) $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'purpose' => $r['purpose'], 'phone' => $r['phone'],
                'status' => $r['status'], 'sector_id' => $sector, 'sector_name' => $r['sector_name'], 'is_default' => (bool) $r['is_default'],
                'connection_state' => $r['connection_state'], 'state_checked_at' => $r['state_checked_at'], 'last_error' => $r['last_error'],
                'conversations' => (int) $r['conversations'], 'active_conversations' => (int) $r['active_conversations'],
                'created_at' => $r['created_at'],
                'webhook_url' => $this->webhookUrl((int) $r['id']),
                'config' => $this->publicConfig($r['kind'], $r['config_encrypted']),
                'attendants' => array_values($people),
                'simulated' => $this->gateways->isSimulated($r['kind'], $this->safeDecrypt($r['config_encrypted'])),
            ];
        }, $rows);
    }

    public function save(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertSupervisor($u);
        $old = $id !== null ? $this->find($id) : null;
        $kind = $old['kind'] ?? (string) ($in['kind'] ?? '');
        if (!isset(self::FIELDS[$kind]) || ($kind === 'lab' && !$this->lab)) {
            throw new DomainError('Tipo de conexão inválido: escolha QR Code ou API oficial.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainError('Nome da conexão: obrigatório, até 80 caracteres.');
        }
        $purpose = (string) ($in['purpose'] ?? $old['purpose'] ?? 'attendance');
        if (!in_array($purpose, ['attendance', 'bulk', 'both'], true)) {
            throw new DomainError('Uso inválido.');
        }
        $phone = preg_replace('/\D+/', '', (string) ($in['phone'] ?? $old['phone'] ?? '')) ?: null;
        if ($phone !== null && !preg_match('/^\d{10,15}$/', $phone)) {
            throw new DomainError('Telefone: só números com DDI e DDD, ex.: 5531999990000.');
        }
        $sector = isset($in['sector_id']) && $in['sector_id'] !== '' && $in['sector_id'] !== null ? (int) $in['sector_id'] : null;
        if ($sector !== null && !$this->db->one('SELECT id FROM talk_sectors WHERE id = ? AND active = 1', [$sector])) {
            throw new DomainError('Setor não encontrado.');
        }

        $cfg = $old ? $this->crypto->decrypt($old['config_encrypted']) : [];
        $given = (array) ($in['config'] ?? []);
        foreach (self::FIELDS[$kind] as $field => $secret) {
            if (!array_key_exists($field, $given)) {
                continue; // não veio: mantém
            }
            $v = trim((string) $given[$field]);
            if ($v !== '') {
                $cfg[$field] = $v;
            } elseif (!$secret) {
                unset($cfg[$field]); // campo comum apagado de propósito; segredo vazio = manter
            }
        }
        if ($kind === 'qrcode') {
            // QR Code: só nome e número. O servidor da Evolution vem do .env (EVOLUTION_URL/EVOLUTION_API_KEY);
            // a instância leva o número. base_url/api_key no próprio canal continuam valendo (instalações antigas).
            $phone = $phone !== null ? Phone::normalize($phone) : null;
            if ($phone === null || !preg_match('/^55\d{10,11}$/', $phone)) {
                throw new DomainError('Número do WhatsApp: informe com DDD, ex.: (31) 99999-0000.');
            }
            if ($old === null && $this->db->one("SELECT id FROM talk_channels WHERE kind = 'qrcode' AND phone = ?", [$phone])) {
                throw new DomainError('Este número já está cadastrado.');
            }
            $cfg['instance'] ??= 'talk-' . $phone;
            $url = (string) ($cfg['base_url'] ?? '');
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
                throw new DomainError('Endereço da Evolution: informe a URL completa, ex.: http://evolution:8080.');
            }
            if (!$this->gateways->evolutionReady() && ($url === '' || empty($cfg['api_key'])) && !$this->gateways->isSimulated('qrcode', $cfg)) {
                throw new DomainError('O servidor do WhatsApp por QR Code (Evolution) ainda não foi configurado: preencha EVOLUTION_URL e EVOLUTION_API_KEY no .env do servidor.');
            }
            $cfg['webhook_secret'] ??= bin2hex(random_bytes(16));
            $purpose = 'attendance'; // envio em massa é só pela API oficial
        }
        if ($kind === 'official') {
            $cfg['verify_token'] ??= bin2hex(random_bytes(12));
        }
        foreach (self::REQUIRED[$kind] as $field) {
            if (empty($cfg[$field])) {
                throw new DomainError('Preencha: ' . $this->label($field) . '.');
            }
        }
        $row = ['name' => $name, 'purpose' => $purpose, 'phone' => $phone, 'sector_id' => $sector, 'config_encrypted' => $this->crypto->encrypt($cfg)];
        if ($id === null) {
            $id = $this->db->insert('talk_channels', $row + ['kind' => $kind, 'connection_state' => 'unknown',
                'is_default' => $this->db->one("SELECT id FROM talk_channels WHERE is_default = 1") ? 0 : 1]);
        } else {
            $credsChanged = $this->crypto->decrypt($old['config_encrypted']) !== $cfg;
            $this->db->run(
                'UPDATE talk_channels SET name = ?, purpose = ?, phone = ?, sector_id = ?, config_encrypted = ?' . ($credsChanged ? ", connection_state = 'unknown'" : '') . ' WHERE id = ?',
                [$name, $purpose, $phone, $sector, $row['config_encrypted'], $id]
            );
        }
        return $this->one($u, $id);
    }

    /** Pede a conexão: no QR Code devolve a imagem para escanear (e configura o webhook na Evolution). */
    public function connect(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        $ch = $this->find($id);
        if ($ch['status'] === 'disabled') {
            throw new DomainError('Ative a conexão antes de conectar.');
        }
        $cfg = $this->crypto->decrypt($ch['config_encrypted']);
        try {
            $r = $this->gateways->forChannel($id)->connect($this->webhookUrl($id, $ch['kind'] === 'qrcode'), $cfg['webhook_secret'] ?? null);
        } catch (GatewayException | \RuntimeException $e) {
            $this->store($id, 'disconnected', null, $e->getMessage());
            throw new DomainError('Não foi possível conectar: ' . $e->getMessage(), 502);
        }
        $this->store($id, $r['state'], $r['phone'] ?? null, $r['state'] === 'disconnected' ? ($r['detail'] ?? null) : null);
        return ['state' => $r['state'], 'qr' => $r['qr'] ?? null, 'detail' => $r['detail'] ?? null, 'channel' => $this->one($u, $id)];
    }

    /** Situação agora (a tela consulta a cada poucos segundos enquanto o QR está aberto). */
    public function refresh(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        $ch = $this->find($id);
        if ($this->gateways->isSimulated($ch['kind'], $this->crypto->decrypt($ch['config_encrypted']))) {
            // laboratório: a situação é a guardada (muda com "Simular leitura")
            return ['state' => $ch['connection_state'], 'detail' => null, 'channel' => $this->one($u, $id)];
        }
        try {
            $r = $this->gateways->forChannel($id)->connectionState();
        } catch (GatewayException | \RuntimeException $e) {
            $r = ['state' => 'disconnected', 'detail' => $e->getMessage()];
        }
        $this->store($id, $r['state'], $r['phone'] ?? null, $r['state'] === 'disconnected' ? ($r['detail'] ?? null) : null);
        return ['state' => $r['state'], 'detail' => $r['detail'] ?? null, 'channel' => $this->one($u, $id)];
    }

    /** Laboratório: faz de conta que o celular leu o QR Code. */
    public function simulateScan(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        $ch = $this->find($id);
        if (!$this->lab || !$this->gateways->isSimulated($ch['kind'], $this->crypto->decrypt($ch['config_encrypted']))) {
            throw new DomainError('Só no laboratório.', 404);
        }
        $this->store($id, 'connected', null, null);
        return $this->one($u, $id);
    }

    public function logout(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        $this->find($id);
        try {
            $this->gateways->forChannel($id)->logout();
        } catch (GatewayException | \RuntimeException $e) {
            throw new DomainError('Não foi possível desconectar: ' . $e->getMessage(), 502);
        }
        $this->store($id, 'disconnected', null, null);
        return $this->one($u, $id);
    }

    /** Número padrão: usado quando o Talk inicia uma conversa (ex.: envio a partir do ERP). */
    public function setDefault(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        if ($this->find($id)['status'] === 'disabled') {
            throw new DomainError('Uma conexão desativada não pode ser a padrão.');
        }
        $this->db->transaction(function () use ($id): void {
            $this->db->run('UPDATE talk_channels SET is_default = 0 WHERE is_default = 1');
            $this->db->run('UPDATE talk_channels SET is_default = 1 WHERE id = ?', [$id]);
        });
        return $this->one($u, $id);
    }

    /** Desativa (para de receber mensagens novas por este número) ou reativa. A padrão não pode ser desativada. */
    public function toggle(StaffMember $u, int $id): array
    {
        $this->assertSupervisor($u);
        $ch = $this->find($id);
        if ($ch['status'] !== 'disabled' && (int) $ch['is_default'] === 1) {
            throw new DomainError('Escolha outra conexão como padrão antes de desativar esta.');
        }
        $this->db->run('UPDATE talk_channels SET status = ? WHERE id = ?', [$ch['status'] === 'disabled' ? 'active' : 'disabled', $id]);
        return $this->one($u, $id);
    }

    /** Excluir só quando o número nunca teve conversa (o histórico aponta para ele); senão, desativar. */
    public function delete(StaffMember $u, int $id): void
    {
        $this->assertSupervisor($u);
        $ch = $this->find($id);
        if ((int) $ch['is_default'] === 1) {
            throw new DomainError('Escolha outra conexão como padrão antes de excluir esta.');
        }
        if ($this->db->one('SELECT id FROM talk_conversations WHERE channel_id = ? LIMIT 1', [$id])
            || $this->db->one('SELECT id FROM talk_outbox WHERE channel_id = ? LIMIT 1', [$id])) {
            throw new DomainError('Este número já tem atendimentos no histórico: desative em vez de excluir.');
        }
        $this->db->run('DELETE FROM talk_channels WHERE id = ?', [$id]);
    }

    public function one(StaffMember $u, int $id): array
    {
        foreach ($this->list($u) as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        throw new DomainError('Conexão não encontrada.', 404);
    }

    private function store(int $id, string $state, ?string $phone, ?string $error): void
    {
        $state = in_array($state, ['connected', 'connecting', 'disconnected'], true) ? $state : 'unknown';
        $this->db->run(
            'UPDATE talk_channels SET connection_state = ?, state_checked_at = NOW(), last_error = ?, phone = COALESCE(?, phone) WHERE id = ?',
            [$state, $error !== null ? mb_substr($error, 0, 255) : null, $phone, $id]
        );
    }

    private function publicConfig(string $kind, string $encrypted): array
    {
        try {
            $cfg = $this->crypto->decrypt($encrypted);
        } catch (\RuntimeException) {
            return ['_error' => 'Configuração ilegível (TALK_KEY trocada?)'];
        }
        $out = [];
        foreach (self::FIELDS[$kind] ?? [] as $field => $secret) {
            $v = (string) ($cfg[$field] ?? '');
            $out[$field] = $secret ? ($v === '' ? '' : '••••' . substr($v, -4)) : $v;
        }
        return $out;
    }

    private function safeDecrypt(string $encrypted): array
    {
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Endereço do webhook. O motor do QR Code roda no mesmo servidor: em produção ele chama o Talk
     * pela rede interna (EVOLUTION_WEBHOOK_URL=http://app), sem sair para a internet e voltar.
     */
    private function webhookUrl(int $id, bool $internal = false): string
    {
        $base = $internal && $this->qrWebhookBase !== '' ? $this->qrWebhookBase : $this->appUrl;
        return rtrim($base, '/') . "/webhook/$id";
    }

    private function label(string $field): string
    {
        return [
            'base_url' => 'endereço da Evolution', 'instance' => 'nome da instância', 'api_key' => 'chave da API (apikey)',
            'phone_number_id' => 'Phone number ID', 'access_token' => 'token de acesso', 'app_secret' => 'App secret',
        ][$field] ?? $field;
    }

    private function find(int $id): array
    {
        $row = $this->db->one('SELECT * FROM talk_channels WHERE id = ?', [$id]);
        if (!$row) {
            throw new DomainError('Conexão não encontrada.', 404);
        }
        return $row;
    }

    private function assertSupervisor(StaffMember $u): void
    {
        if (!$u->supervisor) {
            throw new DomainError('Só o supervisor gerencia as conexões do WhatsApp.', 403);
        }
    }
}
