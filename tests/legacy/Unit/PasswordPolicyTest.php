<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsPasswordInsideConfiguredLength(): void
    {
        self::assertTrue(is_passwd('Senha@123'));
    }

    public function testRejectsPasswordOutsideConfiguredLength(): void
    {
        self::assertFalse(is_passwd('curta'));
        self::assertFalse(is_passwd(str_repeat('a', CONF_PASSWD_MAX_LEN + 1)));
    }

    public function testHashCanBeVerifiedAndDoesNotExposePlainText(): void
    {
        $hash = passwd('Senha@123');
        self::assertNotSame('Senha@123', $hash);
        self::assertTrue(passwd_verify('Senha@123', $hash));
        self::assertFalse(passwd_verify('SenhaErrada', $hash));
        self::assertFalse(passwd_rehash($hash));
    }
}
