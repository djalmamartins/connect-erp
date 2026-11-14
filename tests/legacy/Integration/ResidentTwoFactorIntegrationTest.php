<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Resident\ResidentTwoFactor;
use Source\Services\TwoFactorService;
use Source\Support\Totp;

/**
 * ADR 0304: 2FA opcional para a conta só de morador. Com o 2FA obrigatório ligado, o morador entra no app sem
 * configurar nada; a equipe que também mora continua obrigada. Ligar, desligar e renovar os códigos pedem o código.
 */
final class ResidentTwoFactorIntegrationTest extends TestCase
{
    private string|false $enforce;
    private string|false $key;
    private array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforce = getenv('CONNECT_2FA_ENFORCE_FROM');
        $this->key = getenv('CONNECT_2FA_KEY');
        putenv('CONNECT_2FA_ENFORCE_FROM=2026-01-01');
        if (!\Source\Support\SecretBox::isConfigured()) {
            putenv('CONNECT_2FA_KEY=' . base64_encode(random_bytes(32)));
        }
    }

    protected function tearDown(): void
    {
        putenv($this->enforce === false ? 'CONNECT_2FA_ENFORCE_FROM' : 'CONNECT_2FA_ENFORCE_FROM=' . $this->enforce);
        putenv($this->key === false ? 'CONNECT_2FA_KEY' : 'CONNECT_2FA_KEY=' . $this->key);
        parent::tearDown();
    }

    private function resident(array $roles = ['user'], int $level = 1): array
    {
        $email = 'morador' . bin2hex(random_bytes(3)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'level' => $level]);
        foreach ($roles as $slug) {
            $this->pdo->prepare('INSERT IGNORE INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute([$slug, $slug, $slug === 'user' ? 1 : 5]);
            $this->pdo->prepare('INSERT IGNORE INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = ?')->execute([$userId, $slug]);
        }
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Cond ' . $userId, 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, '101', 'apartamento')")->execute([$condominium]);
        $unit = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, email, user_id) VALUES (?, ?, '101', 'owner', 'Ana', ?, ?)")
            ->execute([$condominium, $unit, $email, $userId]);
        return [$userId, $email];
    }

    private function sessions(): ResidentAppSessions
    {
        return new ResidentAppSessions($this->pdo, new TwoFactorService($this->pdo), new LoginThrottle($this->pdo), fn (User $u): bool => true);
    }

    private function service(): ResidentTwoFactor
    {
        return new ResidentTwoFactor($this->pdo, new TwoFactorService($this->pdo), new LoginThrottle($this->pdo), function (object $u, string $event): bool {
            $this->notices[] = $event;
            return true;
        });
    }

    public function testOnlyPlainResidentIsExemptFromMandatoryTwoFactor(): void
    {
        [$resident] = $this->resident();
        [$staff] = $this->resident(['erp_staff_test']);
        $staffByLevel = $this->resident([], 5)[0];
        $noLink = $this->createUser();

        self::assertTrue(ResidentTwoFactor::isResidentOnly($resident, $this->pdo));
        self::assertFalse(ResidentTwoFactor::enforcedFor($resident, $this->pdo));
        self::assertFalse(ResidentTwoFactor::isResidentOnly($staff, $this->pdo));
        self::assertTrue(ResidentTwoFactor::enforcedFor($staff, $this->pdo));
        self::assertFalse(ResidentTwoFactor::isResidentOnly($staffByLevel, $this->pdo));
        self::assertFalse(ResidentTwoFactor::isResidentOnly($noLink, $this->pdo));

        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE user_id = ?")->execute([$resident]);
        self::assertTrue(ResidentTwoFactor::enforcedFor($resident, $this->pdo), 'Sem vínculo ativo a regra volta a ser a obrigatória.');
    }

    public function testNativeAppLoginSkipsSetupOnlyForPlainResident(): void
    {
        [, $email] = $this->resident();
        self::assertSame(201, $this->sessions()->login($email, 'Senha@123', null)['status'], 'Morador entra sem configurar o 2FA.');

        [, $staffEmail] = $this->resident(['erp_staff_test']);
        $staff = $this->sessions()->login($staffEmail, 'Senha@123', null);
        self::assertSame([409, 'two_factor_setup_required'], [$staff['status'], $staff['body']['error']['code']]);
    }

    public function testResidentTurnsTwoFactorOnAndOffWithCurrentCode(): void
    {
        [$id, $email] = $this->resident();
        $user = (new User())->findById($id);
        $service = $this->service();
        $totp = new Totp();

        $status = $service->status($user);
        self::assertFalse($status['enabled']);
        self::assertFalse($status['required']);
        self::assertStringStartsWith('otpauth://totp/', $status['setup']['uri']);
        $secret = $status['setup']['secret'];
        self::assertSame($secret, $service->status($user)['setup']['secret'], 'O mesmo segredo até confirmar.');

        self::assertSame([false, 422, 'invalid_code'], array_values(array_intersect_key($service->act($user, 'confirm', '000000'), ['ok' => 1, 'status' => 1, 'code' => 1])));
        $on = $service->act($user, 'confirm', $totp->code($secret, $totp->step()));
        self::assertTrue($on['ok']);
        self::assertCount(TwoFactorService::RECOVERY_CODES, $on['recovery_codes']);
        self::assertTrue($service->status($user)['enabled']);
        self::assertArrayNotHasKey('setup', $service->status($user));

        $login = $this->sessions()->login($email, 'Senha@123', null);
        self::assertSame(202, $login['status'], 'Ligado pelo morador, o app pede o código.');

        self::assertSame('invalid_code', $service->act($user, 'disable', '')['code']);
        $renew = $service->act($user, 'recovery', $totp->code($secret, $totp->step() + 1));
        self::assertTrue($renew['ok']);
        self::assertNotSame($on['recovery_codes'], $renew['recovery_codes']);
        // A janela do TOTP é de um passo para cada lado: libera o passo atual de novo para desligar.
        $this->pdo->prepare('UPDATE user_two_factor SET last_used_step = NULL WHERE user_id = ?')->execute([$id]);
        self::assertTrue($service->act($user, 'disable', $totp->code($secret, $totp->step()))['ok']);
        self::assertFalse($service->status($user)['enabled']);
        self::assertSame(['enabled', 'disabled'], $this->notices);
        self::assertSame('not_enabled', $service->act($user, 'disable', '123456')['code']);
    }

    public function testStaffCannotDisableMandatoryTwoFactorHere(): void
    {
        [$id] = $this->resident(['erp_staff_test']);
        $user = (new User())->findById($id);
        $service = $this->service();
        $totp = new Totp();
        $secret = $service->status($user)['setup']['secret'];
        self::assertTrue($service->act($user, 'confirm', $totp->code($secret, $totp->step()))['ok']);
        $off = $service->act($user, 'disable', $totp->code($secret, $totp->step() + 1));
        self::assertSame([409, 'two_factor_required'], [$off['status'], $off['code']]);
        self::assertTrue((new TwoFactorService($this->pdo))->isEnabled($id));
    }

    public function testWrongCodesAreThrottled(): void
    {
        [$id] = $this->resident();
        $user = (new User())->findById($id);
        $service = $this->service();
        $service->status($user);
        $last = [];
        for ($i = 0; $i < 12; $i++) {
            $last = $service->act($user, 'confirm', '000000');
        }
        self::assertSame('too_many_attempts', $last['code']);
    }
}
