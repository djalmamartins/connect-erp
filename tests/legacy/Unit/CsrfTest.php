<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Core\Session;

final class CsrfTest extends TestCase
{
    public function testValidTokenIsAcceptedAndInvalidTokenIsRejected(): void
    {
        $session = new Session();
        $session->csrf();

        self::assertTrue(csrf_verify(['csrf' => $session->csrf_token]));
        self::assertFalse(csrf_verify(['csrf' => 'token-invalido']));
        self::assertFalse(csrf_verify([]));
    }
}
