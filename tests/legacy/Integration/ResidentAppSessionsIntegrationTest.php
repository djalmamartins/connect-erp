<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\Resident\ResidentAppSessions as Sessions;
use Source\Services\SessionRevocation;
use Source\Services\TwoFactorService;
use Source\Support\Totp;

/** Sessão do app do morador (ADR 0303) no MariaDB: login, 2FA, token, expiração, logout e logout global. */
final class ResidentAppSessionsIntegrationTest extends TestCase
{
    private bool $resident = true;

    private function sessions(): Sessions
    {
        return new Sessions($this->pdo, new TwoFactorService($this->pdo), new LoginThrottle($this->pdo), fn (User $u): bool => $this->resident);
    }

    private function user(): array
    {
        $email = 'morador' . bin2hex(random_bytes(3)) . '@test.local';
        return [$this->createUser(['email' => $email]), $email];
    }

    public function testLoginIssuesTokenStoredOnlyAsHashAndAuthenticates(): void
    {
        [$id, $email] = $this->user();
        $result = $this->sessions()->login($email, 'Senha@123', 'App Connect Morador (ios)');
        self::assertSame(201, $result['status']);
        $token = $result['body']['data']['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $row = $this->pdo->query("SELECT * FROM erp_resident_app_tokens WHERE user_id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertSame('App Connect Morador (ios)', $row['device_name']);
        self::assertStringNotContainsString($token, json_encode($this->pdo->query('SELECT * FROM system_audit_logs')->fetchAll(\PDO::FETCH_ASSOC)));

        $user = $this->sessions()->authenticate($token);
        self::assertSame($id, (int)$user?->id);
        self::assertNull($this->sessions()->authenticate(Sessions::newToken()));
        self::assertNull($this->sessions()->authenticate('lixo'));
    }

    public function testWrongPasswordAndNonResidentAreRefused(): void
    {
        [, $email] = $this->user();
        self::assertSame(401, $this->sessions()->login($email, 'Errada@123', null)['status']);
        $this->resident = false;
        $result = $this->sessions()->login($email, 'Senha@123', null);
        self::assertSame([403, 'not_resident'], [$result['status'], $result['body']['error']['code']]);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_resident_app_tokens')->fetchColumn());
    }

    public function testThrottleBlocksAfterRepeatedFailures(): void
    {
        [, $email] = $this->user();
        for ($i = 0; $i < 6; $i++) {
            $this->sessions()->login($email, 'Errada@123', null);
        }
        self::assertSame(429, $this->sessions()->login($email, 'Senha@123', null)['status']);
    }

    public function testTwoFactorChallengeIsSingleUseAndLimited(): void
    {
        [$id, $email] = $this->user();
        $twoFactor = new TwoFactorService($this->pdo);
        $totp = new Totp();
        $secret = $twoFactor->beginSetup($id);
        self::assertIsArray($twoFactor->confirm($id, $totp->code($secret, $totp->step())));

        $first = $this->sessions()->login($email, 'Senha@123', 'Celular');
        self::assertSame(202, $first['status']);
        $challenge = $first['body']['data']['challenge'];
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_resident_app_tokens')->fetchColumn(), 'Sem token antes do 2FA.');

        self::assertSame(422, $this->sessions()->verify($challenge, '000000')['status']);
        $ok = $this->sessions()->verify($challenge, $totp->code($secret, $totp->step() + 1));
        self::assertSame(201, $ok['status']);
        self::assertSame($id, (int)$this->sessions()->authenticate($ok['body']['data']['token'])?->id);
        self::assertSame(401, $this->sessions()->verify($challenge, $totp->code($secret, $totp->step() + 2))['status'], 'Desafio de uso único.');

        $second = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['challenge'];
        for ($i = 0; $i < Sessions::CHALLENGE_ATTEMPTS; $i++) {
            $this->sessions()->verify($second, '111111');
        }
        $this->pdo->exec('TRUNCATE TABLE auth_throttle_events');
        self::assertSame(401, $this->sessions()->verify($second, $totp->code($secret, $totp->step() + 3))['status'], 'Tentativas esgotadas.');
    }

    public function testLogoutIdleAndGlobalLogoutEndTheSession(): void
    {
        [$id, $email] = $this->user();
        $token = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['token'];
        self::assertTrue($this->sessions()->revoke($token));
        self::assertFalse($this->sessions()->revoke($token));
        self::assertNull($this->sessions()->authenticate($token));

        $idle = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['token'];
        self::assertNull($this->sessions()->authenticate($idle, new DateTimeImmutable('+31 days')));

        $global = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['token'];
        (new SessionRevocation($this->pdo))->revokeAll($id, SessionRevocation::nowMs() + 5);
        SessionRevocation::clearCache();
        self::assertNull($this->sessions()->authenticate($global));
        self::assertSame('logout_all', $this->pdo->query("SELECT revoke_reason FROM erp_resident_app_tokens WHERE token_hash = '" . hash('sha256', $global) . "'")->fetchColumn());

        $stillResident = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['token'];
        $this->resident = false;
        self::assertNull($this->sessions()->authenticate($stillResident), 'Deixou de ser morador: o app para de funcionar.');
    }

    public function testDeviceLimitRevokesTheOldestSessions(): void
    {
        [$id, $email] = $this->user();
        $tokens = [];
        for ($i = 0; $i < Sessions::MAX_DEVICES + 2; $i++) {
            $tokens[] = $this->sessions()->login($email, 'Senha@123', null)['body']['data']['token'];
        }
        self::assertSame(Sessions::MAX_DEVICES, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_resident_app_tokens WHERE user_id = {$id} AND revoked_at IS NULL")->fetchColumn());
        self::assertNull($this->sessions()->authenticate($tokens[0]));
        self::assertNotNull($this->sessions()->authenticate(end($tokens)));
    }
}
