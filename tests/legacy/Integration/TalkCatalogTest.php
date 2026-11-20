<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\Talk;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;

/**
 * Dados dos condomínios para o Talk (ADR 0624): só condomínios ativos do escopo, unidades sem morador, áreas que
 * aceitam reserva, comunicados para todos e já liberados; token sem condominiums.read leva 403.
 */
final class TalkCatalogTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $inactive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, ?)')->execute(['Help Desk', 'support.manage', 'Teste']);
        $insert = $this->pdo->prepare('INSERT INTO condominiums (name, status, street, number, district, city, state, zip_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['Alpha', 'active', 'Av. Brasil', '120', 'Centro', 'Campinas', 'SP', '13000000']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $insert->execute(['Beta', 'active', null, null, null, null, null, null]);
        $this->beta = (int)$this->pdo->lastInsertId();
        $insert->execute(['Antigo', 'inactive', null, null, null, null, null, null]);
        $this->inactive = (int)$this->pdo->lastInsertId();
        $agent = $this->createUser(['level' => 5, 'first_name' => 'Connect Talk']);
        $scope = new CondominiumScope($this->pdo);
        $scope->grant($agent, $this->alpha);
        $scope->grant($agent, $this->inactive);
        Access::clear();

        $unit = $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number, status, deleted_at) VALUES (?, ?, ?, ?, ?)');
        foreach ([['A', '101', 'active', null], ['A', '102', 'active', null], ['B', '201', 'inactive', null], ['B', '202', 'active', '2026-11-01 10:00:00']] as $u) {
            $unit->execute([$this->alpha, ...$u]);
        }
        $unit->execute([$this->beta, 'A', '101', 'active', null]);

        $area = $this->pdo->prepare('INSERT INTO erp_common_areas (condominium_id, name, booking_mode, booking_fee_cents, opening_time, closing_time, rules) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([['Salão de festas', 'approval', 15000, '08:00:00', '22:00:00', 'Até 22h'], ['Churrasqueira', 'free', null, null, null, null], ['Academia', 'none', null, null, null, null], ['Piscina', 'blocked', null, null, null, null]] as $a) {
            $area->execute([$this->alpha, ...$a]);
        }

        $note = $this->pdo->prepare('INSERT INTO erp_announcements (condominium_id, kind, subject, body, send_at, status, audience) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $note->execute([$this->alpha, 'general', 'Limpeza da caixa', '<p>Dia <strong>25</strong> sem água.</p>', '2026-11-10 08:00:00', 'active', 'all']);
        $note->execute([$this->alpha, 'general', 'Assembleia', '<p>Dia 30.</p>', '2026-11-15 08:00:00', 'active', 'all']);
        $note->execute([$this->alpha, 'general', 'Só bloco A', 'x', '2026-11-12 08:00:00', 'active', 'units']);
        $note->execute([$this->alpha, 'general', 'Cancelado', 'x', '2026-11-12 08:00:00', 'cancelled', 'all']);
        $note->execute([$this->alpha, 'general', 'Agendado', 'x', '2099-01-01 08:00:00', 'active', 'all']);
        $note->execute([$this->alpha, 'collection', 'Cobrança', 'x', '2026-11-12 08:00:00', 'active', 'all']);

        $user = (new User())->findById($agent);
        Auth::actingAs($user);
        TalkTokens::setCurrent(['id' => 1, 'user' => $user, 'scopes' => ['condominiums.read']]);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        TalkTokens::setCurrent(null);
        unset($_GET['page']);
        parent::tearDown();
    }

    private function call(callable $action): array
    {
        ob_start();
        $action();
        return (array)json_decode((string)ob_get_clean(), true);
    }

    public function testListsOnlyActiveCondominiumsInScopeWithoutSensitiveFields(): void
    {
        $list = $this->call(static fn () => (new Talk())->condominiums());
        self::assertSame([['id' => $this->alpha, 'name' => 'Alpha', 'district' => 'Centro', 'city' => 'Campinas', 'state' => 'SP']], $list['data']);
        self::assertSame(['page' => 1, 'per_page' => 50, 'total' => 1], $list['meta']);

        $one = $this->call(fn () => (new Talk())->condominium(['id' => (string)$this->alpha]));
        self::assertSame('Av. Brasil', $one['data']['address']['street']);
        self::assertSame(['Churrasqueira', 'Salão de festas'], array_column($one['data']['common_areas'], 'name'), 'Sem reserva e bloqueada ficam de fora.');
        $hall = $one['data']['common_areas'][1];
        self::assertSame([15000, '08:00', '22:00', 'approval'], [$hall['fee_cents'], $hall['opening_time'], $hall['closing_time'], $hall['booking_mode']]);
        $json = (string)json_encode($one);
        foreach (['cnpj', 'bank', 'syndic'] as $field) {
            self::assertStringNotContainsStringIgnoringCase($field, $json);
        }

        foreach ([$this->beta, $this->inactive, 999999] as $hidden) {
            $denied = $this->call(static fn () => (new Talk())->condominium(['id' => (string)$hidden]));
            self::assertSame('not_found', $denied['error']['code'], 'Fora do escopo, inativo ou inexistente: mesmo 404.');
            $units = $this->call(static fn () => (new Talk())->units(['id' => (string)$hidden]));
            self::assertSame('not_found', $units['error']['code']);
        }
    }

    public function testUnitsHaveNoResidentAndAnnouncementsAreForEveryone(): void
    {
        $units = $this->call(fn () => (new Talk())->units(['id' => (string)$this->alpha]));
        self::assertSame(['A - 101', 'A - 102'], array_column($units['data'], 'label'));
        self::assertSame(['id', 'block', 'number', 'label', 'type'], array_keys($units['data'][0]));
        self::assertSame(2, $units['meta']['total']);

        $notes = $this->call(fn () => (new Talk())->announcements(['id' => (string)$this->alpha]));
        self::assertSame(['Assembleia', 'Limpeza da caixa'], array_column($notes['data'], 'subject'), 'Mais novo primeiro; sem os de algumas unidades, cancelados, agendados e de cobrança.');
        self::assertSame('Dia 25 sem água.', $notes['data'][1]['body']);
        self::assertSame(2, $notes['meta']['total']);

        $_GET['page'] = '2';
        $empty = $this->call(fn () => (new Talk())->announcements(['id' => (string)$this->alpha]));
        self::assertSame([], $empty['data']);
        self::assertSame(2, $empty['meta']['page']);
    }

    public function testTokenWithoutCondominiumsReadIsRefused(): void
    {
        $current = TalkTokens::current();
        TalkTokens::setCurrent(['scopes' => ['people.read']] + $current);
        foreach (['condominiums' => [], 'condominium' => ['id' => (string)$this->alpha], 'units' => ['id' => (string)$this->alpha], 'announcements' => ['id' => (string)$this->alpha]] as $method => $args) {
            $out = $this->call(static fn () => $args === [] ? (new Talk())->{$method}() : (new Talk())->{$method}($args));
            self::assertSame('insufficient_scope', $out['error']['code'], $method);
            self::assertSame('condominiums.read', $out['error']['scope']);
        }
    }
}
