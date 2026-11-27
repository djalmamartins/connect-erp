<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Services\Directory\PeopleSuggest;

/** Regras puras da sugestão de pessoa e e-mail ao digitar (ADR 0687). */
final class PeopleSuggestTest extends TestCase
{
    public function testCriteriaNeedsThreeLettersAndNormalizes(): void
    {
        try {
            PeopleSuggest::criteria(['q' => ' ma ']);
            self::fail('Duas letras não buscam.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('3 letras', $exception->getMessage());
        }
        $c = PeopleSuggest::criteria(['q' => "  Maria   da  ", 'sources' => 'team,xpto', 'limit' => '99', 'condominium_id' => '7', 'unit_id' => 'abc']);
        self::assertSame(['Maria da', 'person', false, ['team'], PeopleSuggest::MAX_LIMIT, 7, null],
            [$c['q'], $c['kind'], $c['email'], $c['sources'], $c['limit'], $c['condominium_id'], $c['unit_id']]);
        $e = PeopleSuggest::criteria(['q' => 'joa', 'kind' => 'email']);
        self::assertSame([true, PeopleSuggest::SOURCES, PeopleSuggest::DEFAULT_LIMIT], [$e['email'], $e['sources'], $e['limit']]);
        self::assertTrue(PeopleSuggest::criteria(['q' => 'joa', 'with' => 'email'])['email'], 'Campo de nome que preenche o e-mail.');
        self::assertSame(80, mb_strlen(PeopleSuggest::criteria(['q' => str_repeat('a', 200)])['q']));
    }

    public function testGroupsSamePersonAndHidesEmailUnlessAsked(): void
    {
        $rows = [
            ['id' => 3, 'name' => 'Maria Souza', 'email' => 'Maria@Ex.com', 'document' => '12345678901', 'relationship' => 'owner', 'unit_label' => 'Apto 101', 'condominium_name' => 'Alpha', 'account_id' => 9],
            ['id' => 4, 'name' => 'Maria Souza', 'email' => 'maria@ex.com', 'document' => '123.456.789-01', 'relationship' => 'tenant', 'unit_label' => 'Apto 202', 'condominium_name' => 'Alpha', 'account_id' => 9],
            ['id' => 5, 'name' => 'Maria Souza', 'email' => null, 'document' => null, 'relationship' => 'resident', 'unit_label' => '', 'condominium_name' => 'Beta', 'account_id' => null],
        ];
        $hidden = PeopleSuggest::groupResidents($rows, false);
        self::assertCount(2, $hidden, 'Mesmo CPF = uma pessoa; sem documento nem e-mail = outra.');
        self::assertSame(['resident', 3, 9, null, 'Proprietário · Apto 101 · Alpha, Apto 202 · Alpha'],
            [$hidden[0]['source'], $hidden[0]['id'], $hidden[0]['account_id'], $hidden[0]['email'], $hidden[0]['context']]);
        self::assertSame('Morador · Beta', $hidden[1]['context']);
        $json = (string)json_encode($hidden);
        self::assertStringNotContainsString('12345678901', $json, 'Documento nunca sai.');
        self::assertStringNotContainsString('ex.com', $json, 'E-mail só quando o campo pede.');
        self::assertSame('maria@ex.com', PeopleSuggest::groupResidents($rows, true)[0]['email']);
        self::assertArrayNotHasKey('places', $hidden[0]);
    }

    public function testManyPlacesAreSummarized(): void
    {
        $rows = [];
        foreach (['101', '102', '103', '104'] as $i => $unit) {
            $rows[] = ['id' => $i + 1, 'name' => 'Ana', 'email' => 'ana@ex.com', 'document' => null, 'relationship' => 'owner', 'unit_label' => $unit, 'condominium_name' => 'Alpha', 'account_id' => null];
        }
        self::assertSame('Proprietário · 101 · Alpha, 102 · Alpha +2', PeopleSuggest::groupResidents($rows, false)[0]['context']);
    }

    public function testRankPutsPrefixFirstAndIgnoresAccents(): void
    {
        $items = [
            ['name' => 'Ana Júlia', 'email' => null],
            ['name' => 'Juliana Prado', 'email' => null],
            ['name' => 'Marcos Juliano', 'email' => null],
            ['name' => 'Paulo', 'email' => 'julio@ex.com'],
        ];
        self::assertSame(['Juliana Prado', 'Paulo', 'Ana Júlia', 'Marcos Juliano'], array_column(PeopleSuggest::rank($items, 'jul', 8), 'name'));
        self::assertCount(2, PeopleSuggest::rank($items, 'jul', 2));
        self::assertSame('joao conceicao', PeopleSuggest::fold('JOÃO Conceição'));
    }

    public function testSourcesFollowPermissions(): void
    {
        $user = new \Source\Models\User();
        $user->id = 1;
        $user->level = 1;
        $none = new PeopleSuggest(new \PDO('sqlite::memory:'), null, static fn (): bool => false);
        self::assertSame([], $none->allowedSources($user, PeopleSuggest::SOURCES), 'Morador ou conta sem papel não busca ninguém.');
        $erpOnly = new PeopleSuggest(new \PDO('sqlite::memory:'), null, static fn (string $p): bool => $p === 'erp.access');
        self::assertSame(['team'], $erpOnly->allowedSources($user, PeopleSuggest::SOURCES), 'Sem erp.residents.view não vê moradores.');
        $all = new PeopleSuggest(new \PDO('sqlite::memory:'), null, static fn (): bool => true);
        self::assertSame(['residents', 'team'], $all->allowedSources($user, PeopleSuggest::SOURCES));
        $user->level = 5;
        self::assertSame(['team'], $none->allowedSources($user, ['residents', 'team']), 'Equipe do Studio/Help Desk vê a equipe.');
    }
}
