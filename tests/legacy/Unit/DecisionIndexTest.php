<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #9: todo ADR está no índice único e não há dois ADRs com o mesmo número. */
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
        foreach (glob(self::ROOT . '/docs/decisions/ADR-*.md') ?: [] as $file) {
            self::assertStringContainsString(basename($file), $index);
        }
    }
}
