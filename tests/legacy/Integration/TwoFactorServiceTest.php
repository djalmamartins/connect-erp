<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Services\TwoFactorService;
use Source\Support\Totp;

/** 2FA TOTP (Fase 1, e007c18): cadastro, anti-replay, recuperação e remoção. */
final class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;
    private Totp $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Totp();
        $this->service = new TwoFactorService($this->pdo, $this->totp);
    }

    public function testSetupStoresEncryptedSecretAndOnlyConfirmsWithValidCode(): void
    {
        $userId = $this->createUser();
        $secret = $this->service->beginSetup($userId);

        self::assertSame($secret, $this->service->beginSetup($userId), 'Segredo pendente é reaproveitado.');
        $stored = (string)$this->pdo->query("SELECT secret_encrypted FROM user_two_factor WHERE user_id = {$userId}")->fetchColumn();
        self::assertStringNotContainsString($secret, $stored, 'O segredo não pode ficar em texto no banco.');
        self::assertFalse($this->service->isEnabled($userId));

        self::assertNull($this->service->confirm($userId, 'abc123'));
        self::assertFalse($this->service->isEnabled($userId));

        $codes = $this->service->confirm($userId, $this->currentCode($secret));
        self::assertIsArray($codes);
        self::assertCount(TwoFactorService::RECOVERY_CODES, $codes);
        self::assertTrue($this->service->isEnabled($userId));
        self::assertSame(TwoFactorService::RECOVERY_CODES, $this->service->remainingRecoveryCodes($userId));

        $this->expectException(\DomainException::class);
        $this->service->beginSetup($userId);
    }

    public function testVerifyRejectsReplayOfAcceptedCode(): void
    {
        [$userId, $secret, , $confirmedCode] = $this->enabledUser();
        // O código usado na confirmação já consumiu seu passo: reutilizá-lo deve falhar.
        self::assertFalse($this->service->verify($userId, $confirmedCode));

        $next = $this->totp->code($secret, $this->totp->step() + 1);
        self::assertTrue($this->service->verify($userId, $next));
        self::assertFalse($this->service->verify($userId, $next), 'Mesmo código não pode ser usado duas vezes.');
        self::assertFalse($this->service->verify($userId, 'abc'));
    }

    public function testRecoveryCodesAreSingleUseAndHashed(): void
    {
        [$userId, , $codes] = $this->enabledUser();
        $hashes = $this->pdo->query("SELECT code_hash FROM user_recovery_codes WHERE user_id = {$userId}")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($codes as $code) {
            self::assertNotContains(TwoFactorService::normalizeRecoveryCode($code), $hashes);
        }

        self::assertTrue($this->service->verifyRecoveryCode($userId, strtoupper($codes[0])));
        self::assertFalse($this->service->verifyRecoveryCode($userId, $codes[0]), 'Código de recuperação é de uso único.');
        self::assertFalse($this->service->verifyRecoveryCode($userId, 'zzzzz-zzzzz'));
        self::assertSame(TwoFactorService::RECOVERY_CODES - 1, $this->service->remainingRecoveryCodes($userId));

        $fresh = $this->service->regenerateRecoveryCodes($userId);
        self::assertFalse($this->service->verifyRecoveryCode($userId, $codes[1]), 'Regenerar invalida os códigos antigos.');
        self::assertTrue($this->service->verifyRecoveryCode($userId, $fresh[0]));
    }

    public function testSecretsAreBoundToTheirUser(): void
    {
        [$ownerId, $secret] = $this->enabledUser();
        [$otherId] = $this->enabledUser();

        $next = $this->totp->code($secret, $this->totp->step() + 1);
        self::assertFalse($this->service->verify($otherId, $next), 'Código de outro usuário não vale.');
        self::assertTrue($this->service->verify($ownerId, $next));
    }

    public function testDisableRemovesSecretAndRecoveryCodes(): void
    {
        [$userId] = $this->enabledUser();
        $this->service->disable($userId, $userId);

        self::assertFalse($this->service->isEnabled($userId));
        self::assertSame(0, $this->service->remainingRecoveryCodes($userId));
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM user_two_factor WHERE user_id = {$userId}")->fetchColumn());
    }

    /** @return array{0:int,1:string,2:list<string>,3:string} */
    private function enabledUser(): array
    {
        $userId = $this->createUser();
        $secret = $this->service->beginSetup($userId);
        $code = $this->currentCode($secret);
        $codes = $this->service->confirm($userId, $code);
        self::assertIsArray($codes);
        return [$userId, $secret, $codes, $code];
    }

    private function currentCode(string $secret): string
    {
        return $this->totp->code($secret, $this->totp->step());
    }
}
