<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;

final class UserTest extends TestCase
{
    public function testCreatesUserWithHashedPassword(): void
    {
        $user = (new User())->bootstrap('Maria', 'Teste', 'maria@test.local', '52998224725', 'Senha@123');

        self::assertTrue($user->save());
        self::assertGreaterThan(0, (int)$user->id);
        self::assertTrue(passwd_verify('Senha@123', $user->password));
        self::assertSame('Maria Teste', $user->fullName());
    }

    public function testRejectsDuplicateEmailAndInvalidDocument(): void
    {
        $first = (new User())->bootstrap('Maria', 'Um', 'duplicado@test.local', '52998224725', 'Senha@123');
        self::assertTrue($first->save());

        $duplicate = (new User())->bootstrap('Maria', 'Dois', 'duplicado@test.local', '11144477735', 'Senha@123');
        self::assertFalse($duplicate->save());

        $invalid = (new User())->bootstrap('Documento', 'Inválido', 'documento@test.local', '11111111111', 'Senha@123');
        self::assertFalse($invalid->save());
    }
}
