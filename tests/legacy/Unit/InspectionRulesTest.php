<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Source\Services\Inspections\ChecklistText;
use Source\Services\Inspections\Geo;
use Source\Services\Inspections\InspectionError;
use Source\Services\Inspections\InspectionService;
use Source\Services\Inspections\Schedule;

/** Regras puras da vistoria: GPS, periodicidade, formato do checklist e destinatários. */
final class InspectionRulesTest extends TestCase
{
    public function testDistanceMatchesKnownPoints(): void
    {
        // Praça da Sé (SP) → Catedral da Sé: ~ 110 m; um grau de latitude ~ 111,2 km.
        self::assertEqualsWithDelta(111195, Geo::distance(0.0, 0.0, 1.0, 0.0), 50);
        self::assertSame(0.0, Geo::distance(-23.55, -46.63, -23.55, -46.63));
        self::assertEqualsWithDelta(Geo::distance(-23.5503, -46.6340, -23.5513, -46.6340), Geo::distance(-23.5513, -46.6340, -23.5503, -46.6340), 0.001);
    }

    public function testCheckinStatuses(): void
    {
        $reference = ['latitude' => -23.5503, 'longitude' => -46.6340, 'radius_m' => 100];
        self::assertSame('no_signal', Geo::checkin(null, null, null, $reference)['status']);
        self::assertSame('no_reference', Geo::checkin(-23.55, -46.63, 10.0, null)['status']);
        self::assertSame('inside', Geo::checkin(-23.5507, -46.6340, 10.0, $reference)['status'], '~45 m está dentro do raio de 100 m.');
        $far = Geo::checkin(-23.5603, -46.6340, 10.0, $reference);
        self::assertSame('outside', $far['status'], '~1,1 km está fora.');
        self::assertEqualsWithDelta(1112, $far['distance'], 5);
        // Precisão ruim amplia a tolerância, no máximo até o dobro do raio.
        self::assertSame('inside', Geo::checkin(-23.5519, -46.6340, 90.0, $reference)['status'], '~178 m com ±90 m de precisão passa.');
        self::assertSame('outside', Geo::checkin(-23.5525, -46.6340, 5000.0, $reference)['status'], 'Precisão absurda não libera mais que o dobro do raio.');
    }

    public function testCoordinatesAreValidated(): void
    {
        self::assertSame(-23.5505199, Geo::latitude('-23.55051990'));
        self::assertSame(-46.6333, Geo::longitude('-46,6333'));
        self::assertNull(Geo::latitude('91'));
        self::assertNull(Geo::longitude('-181'));
        self::assertNull(Geo::latitude('abc'));
        self::assertNull(Geo::latitude(''));
        self::assertNull(Geo::accuracy('-1'));
        self::assertSame(12.3, Geo::accuracy('12.34'));
    }

    public function testNextDueAndStatus(): void
    {
        $start = new DateTimeImmutable('2026-11-01');
        self::assertSame('2026-11-01', Schedule::nextDue('weekly', $start, null)->format('Y-m-d'), 'Sem vistoria feita, vence no início do plano.');
        self::assertSame('2026-11-12', Schedule::nextDue('weekly', $start, new DateTimeImmutable('2026-11-05 15:30'))->format('Y-m-d'));
        self::assertSame('2026-12-05', Schedule::nextDue('monthly', $start, new DateTimeImmutable('2026-11-05'))->format('Y-m-d'));
        self::assertSame('2027-02-05', Schedule::nextDue('quarterly', $start, new DateTimeImmutable('2026-11-05'))->format('Y-m-d'));

        $today = new DateTimeImmutable('2026-11-08');
        self::assertSame('overdue', Schedule::status('weekly', new DateTimeImmutable('2026-11-07'), $today));
        self::assertSame('due_soon', Schedule::status('weekly', new DateTimeImmutable('2026-11-08'), $today));
        self::assertSame('due_soon', Schedule::status('weekly', new DateTimeImmutable('2026-11-09'), $today));
        self::assertSame('ok', Schedule::status('weekly', new DateTimeImmutable('2026-11-11'), $today));
        self::assertSame('due_soon', Schedule::status('monthly', new DateTimeImmutable('2026-11-13'), $today));
        self::assertFalse(Schedule::valid('daily'));
        $this->expectException(\InvalidArgumentException::class);
        Schedule::nextDue('daily', $start, null);
    }

    public function testChecklistTextParsesSectionsAndPhotoMarks(): void
    {
        $items = ChecklistText::parse("Item solto\n\n# Portaria\n- Portão funcionando\nCâmeras [FOTO]\n#  \nSem seção nova [foto]  ");
        self::assertSame([
            ['position' => 1, 'section' => 'Geral', 'label' => 'Item solto', 'requires_photo' => false],
            ['position' => 2, 'section' => 'Portaria', 'label' => 'Portão funcionando', 'requires_photo' => false],
            ['position' => 3, 'section' => 'Portaria', 'label' => 'Câmeras', 'requires_photo' => true],
            ['position' => 4, 'section' => 'Geral', 'label' => 'Sem seção nova', 'requires_photo' => true],
        ], $items);
        $round = ChecklistText::parse(ChecklistText::format($items));
        self::assertSame($items, $round, 'Formatar e ler de novo preserva os itens.');
    }

    public function testChecklistTextRejectsEmptyAndOversized(): void
    {
        foreach (["", "# Só seção\n\n", str_repeat("x\n", ChecklistText::MAX_ITEMS + 1), str_repeat('a', 201)] as $text) {
            try {
                ChecklistText::parse($text);
                self::fail('Texto inválido aceito.');
            } catch (InspectionError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey('items', $error->errors);
            }
        }
    }

    public function testRecipientsAreNormalizedDeduplicatedAndLimited(): void
    {
        self::assertSame(['sindico@ex.com', 'conselho@ex.com'], InspectionService::recipients("Sindico@ex.com, conselho@ex.com;\nsindico@ex.com"));
        foreach (['', 'nao-e-email', 'a@b.com, x@', implode(',', array_map(static fn(int $i): string => "u{$i}@ex.com", range(1, 11)))] as $text) {
            try {
                InspectionService::recipients($text);
                self::fail("Destinatários inválidos aceitos: {$text}");
            } catch (InspectionError $error) {
                self::assertArrayHasKey('recipients', $error->errors);
            }
        }
    }
}
