<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentLabExamples;
use Source\Services\Resident\ResidentLinks;
use Source\Services\Resident\ResidentPortal;

/**
 * Ajustes de 14/11 do app do morador (ADR 0302): conta com recibo, dados da pessoa, edital, sininho, busca e os
 * exemplos do lab. Sempre presos à unidade do vínculo.
 */
final class ResidentAppExtrasIntegrationTest extends TestCase
{
    private int $condo;
    private int $unit;
    private int $otherUnit;
    private int $account;
    private User $admin;
    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("INSERT INTO condominiums (name, document, status) VALUES ('Alpha', '11047290000145', 'active')")->execute();
        $this->condo = (int)$this->pdo->lastInsertId();
        $this->unit = $this->unit('101');
        $this->otherUnit = $this->unit('102');
        $adminId = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($adminId, $this->condo);
        $this->admin = (new User())->findById($adminId);
        $userId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email, phone, user_id) VALUES (?, ?, '101', 'owner', 'Ana Moradora', '12345678909', 'ana@morador.test', '11999990000', ?)")
            ->execute([$this->condo, $this->unit, $userId]);
        $this->resident = (new User())->findById($userId);
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            (new ResidentPortalPermissions($this->pdo))->set($this->admin, $this->condo, $feature, true);
        }
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.91', 'Taxa condominial', 'receita', 'analitica')")->execute([$this->condo]);
        $this->account = (int)$this->pdo->lastInsertId();
    }

    private function unit(string $number): int
    {
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, ?, 'apartamento')")->execute([$this->condo, $number]);
        return (int)$this->pdo->lastInsertId();
    }

    private function link(): array
    {
        return (new ResidentLinks($this->pdo))->forUser($this->resident)[0];
    }

    private function entry(int $unit, string $due, ?string $paid = null): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, unit_id, description, amount_cents, competence, due_on) VALUES (?, 'receita', ?, ?, 'Taxa condominial', 65000, ?, ?)")
            ->execute([$this->condo, $this->account, $unit, substr($due, 0, 7), $due]);
        $id = (int)$this->pdo->lastInsertId();
        if ($paid !== null) {
            $this->pdo->prepare('UPDATE erp_entries SET paid_on = ? WHERE id = ?')->execute([$paid, $id]);
        }
        return $id;
    }

    public function testEntryShowsReceiptOnlyWhenPaidAndOnlyForTheOwnUnit(): void
    {
        $portal = new ResidentPortal($this->pdo);
        $paid = $this->entry($this->unit, '2026-10-10', '2026-10-09');
        $open = $this->entry($this->unit, '2026-12-10');
        $neighbour = $this->entry($this->otherUnit, '2026-10-10', '2026-10-09');

        $entry = $portal->entry($this->link(), $paid, '2026-11-14');
        self::assertSame('paid', $entry['situation']);
        self::assertSame(['2026-' . str_pad((string)$paid, 6, '0', STR_PAD_LEFT), 'Ana Moradora', '101', 65000, 'Alpha'],
            [$entry['receipt']['number'], $entry['receipt']['payer'], $entry['receipt']['unit_label'], $entry['receipt']['amount_cents'], $entry['receipt']['condominium_name']]);
        self::assertSame('11047290000145', $entry['receipt']['condominium_document']);

        self::assertNull($portal->entry($this->link(), $open, '2026-11-14')['receipt']);
        try {
            $portal->receipt($this->link(), $open, '2026-11-14');
            self::fail('Conta em aberto não tem recibo.');
        } catch (DomainException) {
        }
        $this->expectException(RegistryNotFound::class);
        $portal->entry($this->link(), $neighbour, '2026-11-14');
    }

    public function testReceiptIsAudited(): void
    {
        $paid = $this->entry($this->unit, '2026-10-10', '2026-10-09');
        (new ResidentPortal($this->pdo))->receipt($this->link(), $paid, '2026-11-14');
        $row = $this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries' AND entity_id = {$paid} AND action = 'read'")->fetchColumn();
        self::assertSame(1, (int)$row);
    }

    public function testProfileMasksTheDocument(): void
    {
        $profile = (new ResidentPortal($this->pdo))->profile($this->link(), $this->resident);
        self::assertSame(['Ana Moradora', '********909', 'ana@morador.test', '11999990000'], [$profile['name'], $profile['document'], $profile['email'], $profile['phone']]);
        self::assertSame((string)$this->resident->email, $profile['login_email']);
    }

    public function testAssemblyCarriesVoteRulesForTheNotice(): void
    {
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status) VALUES (?, 'ordinaria', 'presencial', 'AGO 2026', 'Salão', '2026-11-29 19:00:00', 'convened')")->execute([$this->condo]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_assembly_items (assembly_id, position, title, vote_basis, quorum) VALUES (?, 1, 'Contas do ano', 'fraction', 'simple')")->execute([$id]);
        $item = (new ResidentPortal($this->pdo))->assembly($this->link(), $id)['items'][0];
        self::assertSame(['fraction', 'maioria simples dos votos'], [$item['vote_basis'], $item['quorum_label']]);
    }

    public function testBellAndSearchOnlyShowTheOwnUnitAndEnabledFeatures(): void
    {
        $overdue = $this->entry($this->unit, '2026-11-01');
        $this->entry($this->otherUnit, '2026-11-01');
        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, tracking_code, received_on) VALUES (?, ?, 'Ana', 'correios', 'AB1', CURDATE()), (?, ?, 'Beto', 'amazon', 'ZZ9', CURDATE())")
            ->execute([$this->condo, $this->unit, $this->condo, $this->otherUnit]);
        $this->pdo->prepare("INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at) VALUES (?, 'general', 'Limpeza da caixa d’água', 'Terça das 8h às 12h', 'all', NOW() - INTERVAL 1 HOUR)")->execute([$this->condo]);

        $feed = new ResidentFeed($this->pdo);
        $items = $feed->notifications($this->link(), date('Y-m-d'));
        $kinds = array_column($items, 'kind');
        sort($kinds);
        self::assertSame(['announcement', 'charge', 'delivery'], $kinds);
        self::assertContains('/boletos/' . $overdue, array_column($items, 'path'));
        self::assertStringNotContainsString('Beto', json_encode($items));

        self::assertSame(['Limpeza da caixa d’água'], array_column($feed->search($this->link(), 'AGUA'), 'title'));
        self::assertSame([], $feed->search($this->link(), 'ZZ9'), 'Encomenda do vizinho não aparece.');
        self::assertSame([], $feed->search($this->link(), 'a'), 'Busca curta não procura.');

        (new ResidentPortalPermissions($this->pdo))->set($this->admin, $this->condo, 'announcements', false);
        self::assertSame([], $feed->search($this->link(), 'agua'), 'Função desligada não aparece na busca.');
    }

    public function testLabExamplesAreCreatedOnceAndVisibleInTheApp(): void
    {
        $root = sys_get_temp_dir() . '/resident-examples-' . bin2hex(random_bytes(4));
        $examples = new ResidentLabExamples($this->pdo, $this->admin, $root);
        $first = $examples->create(['condominium_id' => $this->condo, 'unit_id' => $this->unit, 'name' => 'Ana Moradora']);
        self::assertSame('2 criadas (1 esperando retirada)', $first['Encomendas']);
        $again = $examples->create(['condominium_id' => $this->condo, 'unit_id' => $this->unit, 'name' => 'Ana Moradora']);
        self::assertSame(['já existiam', 'já existiam', 'a unidade já tem'], [$again['Comunicados'], $again['Encomendas'], $again['Pet']]);

        $portal = new ResidentPortal($this->pdo);
        $link = $this->link();
        self::assertCount(2, $portal->deliveries($link));
        self::assertCount(2, array_filter($portal->announcements($link), static fn (array $a): bool => str_ends_with($a['subject'], ResidentLabExamples::MARK)));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_documents WHERE shared_with_residents = 1 AND condominium_id = {$this->condo}")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE condominium_id = {$this->condo}")->fetchColumn(), 'Nada é enviado.');
    }
}
