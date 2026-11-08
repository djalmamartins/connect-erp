<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\PasswordResetService;
use Source\Support\PasswordResetToken;

/** Recuperação de senha: hash no banco, uso único, expiração e troca atômica. */
final class PasswordResetServiceTest extends TestCase
{
    public function testIssueStoresOnlyHashAndTokenIsSingleUse(): void
    {
        $userId = $this->createUser();
        $service = new PasswordResetService($this->pdo);
        $token = $service->issue($userId, '127.0.0.1');

        $stored = (string)$this->pdo->query("SELECT token_hash FROM user_password_resets WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame(PasswordResetToken::hash($token), $stored);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM user_password_resets WHERE token_hash = " . $this->pdo->quote($token))->fetchColumn());

        self::assertSame($userId, $service->findValidUserId($token));
        self::assertSame($userId, $service->consume($token));
        self::assertNull($service->consume($token), 'Token não pode ser usado duas vezes.');
        self::assertNull($service->findValidUserId($token));
    }

    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $userId = $this->createUser();
        $service = new PasswordResetService($this->pdo);
        $token = $service->issue($userId);
        $later = time() + PasswordResetToken::TTL_SECONDS + 1;

        self::assertNull($service->findValidUserId($token, $later));
        self::assertNull($service->consume($token, $later));
        self::assertNull($service->consume('nao-e-um-token'));
        self::assertSame($userId, $service->consume($token), 'Dentro do prazo continua válido.');
    }

    public function testNewRequestInvalidatesPreviousToken(): void
    {
        $userId = $this->createUser();
        $service = new PasswordResetService($this->pdo);
        $old = $service->issue($userId);
        $new = $service->issue($userId);

        self::assertNull($service->findValidUserId($old));
        self::assertSame($userId, $service->findValidUserId($new));
    }

    public function testResetWithTokenChangesPasswordAndKeepsTokenWhenValidationFails(): void
    {
        $userId = $this->createUser();
        $token = (new PasswordResetService($this->pdo))->issue($userId);
        $auth = new Auth();

        self::assertFalse($auth->resetWithToken($token, 'NovaSenha@123', 'Diferente@123'));
        self::assertTrue($auth->resetTokenIsValid($token), 'Falha de validação não consome o token.');

        self::assertTrue($auth->resetWithToken($token, 'NovaSenha@123', 'NovaSenha@123'));
        self::assertTrue(password_verify('NovaSenha@123', (string)(new User())->findById($userId)->password));
        self::assertFalse($auth->resetTokenIsValid($token));
        self::assertFalse((new Auth())->resetWithToken($token, 'Outra@1234', 'Outra@1234'));
    }

    public function testForgetAnswersTheSameForUnknownAccountWithoutCreatingToken(): void
    {
        self::assertTrue((new Auth())->forget('ninguem@connect.test'));
        self::assertTrue((new Auth())->forget('123.456.789-01'));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM user_password_resets')->fetchColumn());
        self::assertFalse((new Auth())->forget('isto nao e email'));
    }
}
