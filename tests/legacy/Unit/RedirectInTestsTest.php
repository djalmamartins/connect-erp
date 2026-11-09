<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\RedirectIntercepted;

/** redirect() não pode dar exit na suíte: o PHPUnit parava no meio, sem falha nem relatório. */
final class RedirectInTestsTest extends TestCase
{
    public function testRedirectThrowsInsteadOfExitingDuringTests(): void
    {
        self::assertSame('testing', getenv('MOVESOS_ENV'));
        try {
            redirect('https://studio.connect.test/studio/login');
            self::fail('redirect() deveria lançar RedirectIntercepted nos testes.');
        } catch (RedirectIntercepted $redirect) {
            self::assertSame('https://studio.connect.test/studio/login', $redirect->location);
        }
    }

    public function testRelativeRedirectCarriesTheResolvedUrl(): void
    {
        $this->expectException(RedirectIntercepted::class);
        $this->expectExceptionMessage('/studio/login');
        redirect('/studio/login');
    }
}
