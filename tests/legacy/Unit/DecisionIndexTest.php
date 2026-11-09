<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #9: todo ADR está no índice único, não há dois ADRs com o mesmo número e o próximo livre está livre. */
final class DecisionIndexTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testEveryDecisionIsIndexedAndNumbersAreUnique(): void
    {
        $index = (string)file_get_contents(self::ROOT . '/docs/decisions/README.md');
        $numbers = [];
        foreach (glob(self::ROOT . '/docs/Organic Code/decisions/*.md') ?: [] as $file) {
            $name = basename($file);
            self::assertMatchesRegularExpression('/^\d{4}-[a-z0-9-]+\.md$/', $name);
            $numbers[] = substr($name, 0, 4);
            self::assertStringContainsString($name, $index, "ADR {$name} fora de docs/decisions/README.md");
        }
        self::assertSame(array_unique($numbers), $numbers, 'Dois ADRs com o mesmo número.');
        // O "Próximo número livre" do índice é o que as threads usam para numerar: precisa
        // estar acima do maior ADR da sequência até 0099, senão o próximo ADR colide com um já mergeado.
        // De 0100 em diante, as faixas são por frente (0100–0119 Preparar, 0120–0139 layout; ADR 0100)
        // e cada frente segue a própria; o número indicado só não pode estar em uso.
        self::assertMatchesRegularExpression('/Próximo número livre: (\d{4})/u', $index);
        preg_match('/Próximo número livre: (\d{4})/u', $index, $next);
        $numbers = array_map('intval', $numbers);
        $sequence = array_filter($numbers, static fn (int $n): bool => $n < 100);
        self::assertGreaterThan(max($sequence), (int)$next[1], 'Próximo número livre do índice já está em uso.');
        self::assertNotContains((int)$next[1], $numbers, 'Próximo número livre do índice já está em uso.');
        foreach (glob(self::ROOT . '/docs/decisions/ADR-*.md') ?: [] as $file) {
            self::assertStringContainsString(basename($file), $index);
        }
    }
}
