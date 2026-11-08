<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Models\Auth;

/** Issue #7, risco 3: o hash fictício precisa custar o mesmo que o hash real de uma senha. */
final class AuthDummyHashTest extends TestCase
{
    public function testDummyHashMatchesConfiguredAlgorithmAndCost(): void
    {
        $dummy = password_get_info(Auth::DUMMY_PASSWORD_HASH);
        $real = password_get_info(passwd('Senha-de-teste-123'));

        self::assertSame($real['algo'], $dummy['algo']);
        self::assertSame($real['options'], $dummy['options']);
        self::assertFalse(password_needs_rehash(Auth::DUMMY_PASSWORD_HASH, CONF_PASSWD_ALGO, CONF_PASSWD_OPTION));
    }

    public function testDummyHashNeverMatchesTypicalPasswords(): void
    {
        foreach (['', '12345678', 'password', 'Senha-de-teste-123'] as $password) {
            self::assertFalse(passwd_verify($password, Auth::DUMMY_PASSWORD_HASH));
        }
    }
}
