<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Erp\NullHelpdesk;
use Source\Services\Talk\Gateway\GatewayFactory;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\ConversationService;
use Source\Services\Talk\Contracts\ContactDirectory;
use Source\Services\Talk\Contracts\Helpdesk;
use Source\Services\Talk\Support\Crypto;
use Source\Services\Talk\Support\Db;
use MovesOSTests\Fakes\Talk\FakeHttpClient;
use MovesOSTests\TestCase;

abstract class DbTestCase extends TestCase
{
    protected Db $db;
    protected Crypto $crypto;
    protected FakeHttpClient $http;
    protected ConversationService $svc;
    protected int $channelId;
    protected array $sectors = [];
    protected string $mediaRoot;
    protected \Source\Services\Talk\Media\MediaStore $media;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Mesmo banco de teste do ERP (ADR 0324), numa conexão própria como no TalkApp; as tabelas talk_* vêm da
        // migration 20261116_1300 (aplicada pelo bootstrap) e são zeradas a cada teste.
        $this->db = \Source\Services\Talk\TalkApp::db();
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SHOW TABLES LIKE 'talk\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->mediaRoot = sys_get_temp_dir() . '/talk-media-' . bin2hex(random_bytes(4));
        $this->media = new \Source\Services\Talk\Media\MediaStore($this->mediaRoot);
        $this->crypto = new Crypto(str_repeat('k', 32));
        $this->http = new FakeHttpClient();
        $this->channelId = $this->db->insert('talk_channels', [
            'name' => 'Atendimento', 'kind' => 'qrcode', 'purpose' => 'attendance',
            'config_encrypted' => $this->crypto->encrypt(['base_url' => 'http://evo:8080', 'instance' => 'connect', 'api_key' => 'k', 'webhook_secret' => 's3cret']),
        ]);
        foreach ([['Financeiro', '1', 'boleto, pagamento, segunda via, taxa'], ['Administrativo', '2', 'assembleia, reserva, mudança'], ['Manutenção', '3', 'vazamento, elevador, portão, lâmpada']] as [$n, $k, $kw]) {
            $this->sectors[$n] = $this->db->insert('talk_sectors', ['name' => $n, 'menu_key' => $k, 'keywords' => $kw,
                'jack_key' => strtolower(str_replace(['ç', 'ã'], ['c', 'a'], $n))]);
        }
        $this->svc = $this->makeService();
    }

    protected function makeService(?ContactDirectory $dir = null, ?Helpdesk $hd = null): ConversationService
    {
        return new ConversationService(
            $this->db,
            new EventBus($this->db),
            new AccessPolicy(),
            $dir ?? new \Source\Services\Talk\Erp\NullContactDirectory(),
            $hd ?? new NullHelpdesk(),
            new \Source\Services\Talk\Service\Texts(),
            new \Source\Services\Talk\Jack\Jack($this->db),
            $this->media,
            hours: new \Source\Services\Talk\Service\BusinessHours($this->db),
        );
    }

    /** Roda a triagem da Jack como se a pessoa tivesse parado de escrever há $seconds segundos. */
    protected function triage(int $seconds = 10): int
    {
        return $this->svc->triage($seconds);
    }

    /** Mensagem do cliente + triagem: a conversa já sai na fila do setor. */
    protected function routed(string $text, string $phone = '5531999998888'): int
    {
        $id = (int) $this->inbound($text, $phone);
        $this->triage();
        return $id;
    }

    protected function tearDown(): void
    {
        if (isset($this->mediaRoot) && is_dir($this->mediaRoot)) {
            exec('rm -rf ' . escapeshellarg($this->mediaRoot));
        }
        parent::tearDown();
    }

    protected function gateways(): GatewayFactory
    {
        return new GatewayFactory($this->db, $this->crypto, $this->http);
    }

    protected function inbound(string $text, string $phone = '5531999998888', ?string $id = null): ?int
    {
        return $this->svc->handleInbound($this->channelId, new InboundMessage($id ?? 'IN' . (++$this->seq), $phone, 'text', $text, 'Maria Silva'));
    }

    protected function agent(int $userId, string $sector): StaffMember
    {
        return new StaffMember($userId, false, [$this->sectors[$sector]]);
    }

    protected function conv(int $id): array
    {
        return $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$id]);
    }

    protected function lastOutbound(int $convId): string
    {
        return (string) $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 1", [$convId])['body'];
    }
}
