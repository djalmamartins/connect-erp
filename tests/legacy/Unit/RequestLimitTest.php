<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

final class RequestLimitTest extends TestCase
{
    public function testBlocksAfterLimitAndCanBeReset(): void
    {
        self::assertFalse(request_limit('login-test', 2, 60));
        self::assertFalse(request_limit('login-test', 2, 60));
        self::assertTrue(request_limit('login-test', 2, 60));
        self::assertFalse(request_limit('login-test', 2, 60, true));
        self::assertFalse(request_limit('login-test', 2, 60));
    }
}
