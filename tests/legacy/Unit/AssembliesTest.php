<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Assemblies;

/** Assembleia e votação (ADR 0088): apuração, peso, pauta, edital, ata e CSV. */
final class AssembliesTest extends TestCase
{
    public function testDecideByQuorum(): void
    {
        $s = Assemblies::SCALE;
        self::assertTrue(Assemblies::decide(3 * $s, 2 * $s, 5 * $s, 20 * $s, 'simple')['approved'], 'Simples: mais sim que não; abstenção não conta.');
        self::assertFalse(Assemblies::decide(2 * $s, 2 * $s, 0, 20 * $s, 'simple')['approved'], 'Empate não aprova.');
        self::assertFalse(Assemblies::decide(10 * $s, 0, 0, 20 * $s, 'absolute')['approved'], 'Absoluta: precisa de mais da metade do condomínio.');
        self::assertTrue(Assemblies::decide(10 * $s + 1, 0, 0, 20 * $s, 'absolute')['approved']);
        self::assertTrue(Assemblies::decide(666667, 0, 0, $s, 'two_thirds')['approved'], 'Dois terços da fração.');
        self::assertFalse(Assemblies::decide(666666, 0, 0, $s, 'two_thirds')['approved']);
        self::assertTrue(Assemblies::decide(20 * $s, 0, 0, 20 * $s, 'unanimity')['approved']);
        self::assertFalse(Assemblies::decide(19 * $s, 0, $s, 20 * $s, 'unanimity')['approved'], 'Uma abstenção quebra a unanimidade.');
        self::assertFalse(Assemblies::decide(0, 0, 0, 0, 'simple')['approved'], 'Sem total, nada aprova.');
    }

    public function testDecideRejectsBadInput(): void
    {
        foreach ([[1, 0, 0, 1, 'maioria'], [-1, 0, 0, 1, 'simple'], [2, 0, 0, 1, 'simple']] as $args) {
            try {
                Assemblies::decide(...$args);
                self::fail('Aceitou apuração inválida: ' . json_encode($args));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWeight(): void
    {
        self::assertSame(Assemblies::SCALE, Assemblies::weight('unit', null));
        self::assertSame(125000, Assemblies::weight('fraction', '0.125000'));
        $this->expectException(DomainException::class);
        Assemblies::weight('fraction', null);
    }

    public function testAgendaAndDates(): void
    {
        $items = Assemblies::agenda([['title' => 'Aprovar contas', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
            ['title' => 'Alterar a convenção', 'description' => 'Artigo 5', 'vote_basis' => 'fraction', 'quorum' => 'two_thirds']]);
        self::assertSame(['title' => 'Aprovar contas', 'description' => null, 'vote_basis' => 'fraction', 'quorum' => 'simple'], $items[0]);
        self::assertSame('2026-12-01 19:00', Assemblies::dateTime('2026-12-01T19:00', 'x')->format('Y-m-d H:i'));
        foreach ([[], array_fill(0, Assemblies::MAX_ITEMS + 1, ['title' => 'Item', 'vote_basis' => 'unit', 'quorum' => 'simple']),
            [['title' => 'Item', 'vote_basis' => 'pessoa', 'quorum' => 'simple']], [['title' => 'Item', 'vote_basis' => 'unit', 'quorum' => '50%']]] as $bad) {
            try {
                Assemblies::agenda($bad);
                self::fail('Aceitou pauta inválida.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Assemblies::dateTime('2026-02-30 10:00', 'a data');
    }

    public function testNoticeAndMinutesText(): void
    {
        $assembly = ['kind' => 'ordinaria', 'mode' => 'presencial', 'title' => 'AGO 2026', 'location' => 'Salão de festas', 'first_call_at' => '2026-12-01 19:00:00',
            'second_call_at' => '2026-12-01 19:30:00', 'opened_at' => '2026-12-01 19:31:00', 'closed_at' => '2026-12-01 21:00:00'];
        $items = [['title' => 'Aprovar contas', 'vote_basis' => 'fraction', 'quorum' => 'simple', 'result_json' => json_encode(['yes' => 600000, 'no' => 100000, 'abstain' => 0, 'total' => 1000000, 'votes' => 3, 'approved' => true])]];
        $notice = Assemblies::noticeText($assembly, $items);
        self::assertStringContainsString('Segunda convocação: 2026-12-01 19:30', $notice);
        self::assertStringContainsString('1. Aprovar contas (voto por fração ideal, maioria simples dos votos)', $notice);
        self::assertStringContainsString('art. 1.335, III', $notice);
        $minutes = Assemblies::minutesText($assembly, [['unit_label' => '101', 'is_proxy' => 1, 'eligible' => 1], ['unit_label' => '102', 'is_proxy' => 0, 'eligible' => 0]], $items);
        self::assertStringContainsString('Unidades presentes: 2 (1 por procuração). Sem direito a voto por conta vencida: 1 (102).', $minutes);
        self::assertStringContainsString('Sim: 0,600000. Não: 0,100000.', $minutes);
        self::assertStringContainsString('Resultado: APROVADO.', $minutes);
    }

    public function testCsvIsSafe(): void
    {
        $table = Assemblies::csvTable('attendance', ['rows' => [['unit_label' => '=HYPERLINK("x")', 'attendee_name' => '@Bia', 'is_proxy' => true, 'eligible' => false,
            'overdue_cents' => 5000, 'checked_in_at' => '2026-12-01 19:31:00']]]);
        self::assertSame(["'=HYPERLINK(\"x\")", "'@Bia", 'sim', 'não', '5000', '2026-12-01 19:31:00'], $table['rows'][0]);
        $this->expectException(InvalidArgumentException::class);
        Assemblies::csvTable('votos', ['rows' => []]);
    }
}
