<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\Talk;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkResidents;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;
use Source\Support\IpRateLimit;

/**
 * Consultas do Talk em nome do morador (ADR 0632): telefone do cadastro no X-Resident-Phone, o mesmo vínculo e as
 * mesmas funções do app, 404 sem motivo, 429 depois de erros demais e auditoria com o telefone mascarado.
 */
final class TalkResidentsTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $unit;
    private int $resident;
    private int $outside;
    private int $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, ?)')->execute(['Help Desk', 'support.manage', 'Teste']);
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->agent = $this->createUser(['level' => 5, 'first_name' => 'Connect Talk']);
        (new CondominiumScope($this->pdo))->grant($this->agent, $this->alpha);
        Access::clear();

        $unit = $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)');
        $unit->execute([$this->alpha, 'A', '101']);
        $this->unit = (int)$this->pdo->lastInsertId();
        $unit->execute([$this->alpha, 'A', '102']);
        $neighbor = (int)$this->pdo->lastInsertId();
        $unit->execute([$this->beta, 'B', '201']);
        $betaUnit = (int)$this->pdo->lastInsertId();

        $resident = $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, phone, ends_on) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $resident->execute([$this->alpha, $this->unit, 'A - 101', 'tenant', 'Maria Souza', '31999998888', null]);
        $this->resident = (int)$this->pdo->lastInsertId();
        $resident->execute([$this->beta, $betaUnit, 'B - 201', 'owner', 'Fora Escopo', '31999998888', null]);
        $this->outside = (int)$this->pdo->lastInsertId();

        foreach (['announcements', 'reservations', 'deliveries', 'assemblies', 'boletos'] as $feature) {
            $this->pdo->prepare('INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled) VALUES (?, ?, 1)')->execute([$this->alpha, $feature]);
        }
        $delivery = $this->pdo->prepare('INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on) VALUES (?, ?, ?, ?, CURDATE())');
        $delivery->execute([$this->alpha, $this->unit, 'Maria', 'correios']);
        $delivery->execute([$this->alpha, $neighbor, 'Vizinho', 'outra']);
        $note = $this->pdo->prepare('INSERT INTO erp_announcements (condominium_id, kind, subject, body, send_at, status, audience) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $note->execute([$this->alpha, 'general', 'Para todos', '<p>Oi</p>', '2026-11-10 08:00:00', 'active', 'all']);
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status) VALUES (?, 'ordinaria', 'presencial', ?, ?, ?, 'convened')")
            ->execute([$this->beta, 'Assembleia do Beta', 'Salão', '2026-12-01 19:00:00']);
        $this->betaAssembly = (int)$this->pdo->lastInsertId();

        $user = (new User())->findById($this->agent);
        Auth::actingAs($user);
        // Token com id novo a cada teste: a conta de erros do limite (arquivo em disco) não passa de um teste para outro.
        TalkTokens::setCurrent(['id' => random_int(1_000_000, 9_999_999), 'user' => $user, 'scopes' => ['residents.read']]);
        $_SERVER['HTTP_X_RESIDENT_PHONE'] = '+55 31 99999-8888';
    }

    private int $betaAssembly;

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        TalkTokens::setCurrent(null);
        unset($_SERVER['HTTP_X_RESIDENT_PHONE']);
        parent::tearDown();
    }

    private function call(callable $action): array
    {
        ob_start();
        $action();
        return (array)json_decode((string)ob_get_clean(), true);
    }

    public function testResidentWithMatchingPhoneSeesWhatTheAppShows(): void
    {
        $me = $this->call(fn () => (new Talk())->resident(['id' => (string)$this->resident]));
        self::assertSame('Maria', $me['data']['first_name']);
        self::assertSame(['id' => $this->unit, 'label' => 'A - 101'], $me['data']['unit']);
        self::assertSame(['reservations', 'announcements', 'deliveries'], $me['data']['features'], 'Inquilino: boletos ficam de fora do Talk e assembleia é do proprietário (ADR 0683).');
        self::assertStringNotContainsString('99999', (string)json_encode($me), 'O telefone não volta na resposta.');

        $deliveries = $this->call(fn () => (new Talk())->residentDeliveries(['id' => (string)$this->resident]));
        self::assertSame(['Maria'], array_column($deliveries['data'], 'recipient'), 'Só a encomenda da própria unidade.');
        $notes = $this->call(fn () => (new Talk())->residentAnnouncements(['id' => (string)$this->resident]));
        self::assertSame(['Para todos'], array_column($notes['data'], 'subject'));

        $off = $this->call(fn () => (new Talk())->residentDocuments(['id' => (string)$this->resident]));
        self::assertSame('feature_disabled', $off['error']['code'], 'Função desligada no condomínio, como no app.');
        self::assertSame('documents', $off['error']['feature']);

        $foreign = $this->call(fn () => (new Talk())->residentAssembly(['id' => (string)$this->resident, 'assembly' => (string)$this->betaAssembly]));
        self::assertSame('feature_disabled', $foreign['error']['code'], 'Inquilino: assembleia é só do proprietário (ADR 0683), antes de olhar o condomínio.');
        $area = $this->call(fn () => (new Talk())->residentAreaAvailability(['id' => (string)$this->resident, 'area' => '999999']));
        self::assertSame('not_found', $area['error']['code']);

        $audit = $this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_residents' AND entity_id = {$this->resident} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('area_availability', (string)$audit, 'Cada consulta vai para a auditoria.');
        self::assertStringNotContainsString('99999', (string)$audit, 'Auditoria sem o telefone.');
    }

    public function testWrongPhoneOutOfScopeOrMissingHeaderAreRefused(): void
    {
        $_SERVER['HTTP_X_RESIDENT_PHONE'] = '31988887777';
        self::assertSame('not_found', $this->call(fn () => (new Talk())->resident(['id' => (string)$this->resident]))['error']['code']);
        $_SERVER['HTTP_X_RESIDENT_PHONE'] = '31999998888';
        self::assertSame('not_found', $this->call(fn () => (new Talk())->resident(['id' => (string)$this->outside]))['error']['code'], 'Fora do escopo do usuário de integração.');
        $this->pdo->exec("UPDATE erp_residents SET ends_on = '2020-01-01' WHERE id = {$this->resident}");
        self::assertSame('not_found', $this->call(fn () => (new Talk())->resident(['id' => (string)$this->resident]))['error']['code'], 'Vínculo encerrado.');
        unset($_SERVER['HTTP_X_RESIDENT_PHONE']);
        $missing = $this->call(fn () => (new Talk())->resident(['id' => (string)$this->resident]));
        self::assertSame('validation_failed', $missing['error']['code']);
        self::assertArrayHasKey('phone', $missing['error']['fields']);

        TalkTokens::setCurrent(['scopes' => ['people.read']] + (array)TalkTokens::current());
        $_SERVER['HTTP_X_RESIDENT_PHONE'] = '31999998888';
        $denied = $this->call(fn () => (new Talk())->resident(['id' => (string)$this->resident]));
        self::assertSame('insufficient_scope', $denied['error']['code']);
        self::assertSame('residents.read', $denied['error']['scope']);
    }

    public function testTooManyMissesFromTheSameTokenGive429(): void
    {
        $dir = sys_get_temp_dir() . '/talk-miss-' . bin2hex(random_bytes(4));
        $service = new TalkResidents($this->pdo, null, new IpRateLimit($dir));
        $agent = (new User())->findById($this->agent);
        $now = 1_800_000_000;
        $statuses = [];
        for ($i = 0; $i <= TalkResidents::MISS_LIMIT[0]; $i++) {
            try {
                $service->link($agent, $this->resident, '31900000000', 'profile', 91, null, $now);
            } catch (TicketError $error) {
                $statuses[] = $error->status();
            }
        }
        self::assertSame(array_merge(array_fill(0, TalkResidents::MISS_LIMIT[0], 404), [429]), $statuses);
        self::assertSame($this->resident, $service->link($agent, $this->resident, '31999998888', 'profile', 91, null, $now)['resident_id'], 'O telefone certo ainda passa.');
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}
