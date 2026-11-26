<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Team\TeamAppSessions;
use Source\Services\Team\TeamPush;
use Source\Services\TwoFactorService;
use Source\Support\Middleware\ApiAuthenticated;

/** Apps da equipe (ADR 0660): sessão Bearer separada da do morador, middleware da API e fila do push. */
final class TeamAppIntegrationTest extends TestCase
{
    private bool $team = true;

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        Auth::actingAs(null);
        parent::tearDown();
    }

    private function sessions(): ResidentAppSessions
    {
        return TeamAppSessions::make($this->pdo, fn (User $u): bool => $this->team);
    }

    private function resident(): ResidentAppSessions
    {
        return new ResidentAppSessions($this->pdo, new TwoFactorService($this->pdo), new LoginThrottle($this->pdo), fn (User $u): bool => true);
    }

    private function developer(): array
    {
        $this->registerMigrationPermissions();
        $email = 'equipe' . bin2hex(random_bytes(3)) . '@test.local';
        $id = $this->createUser(['email' => $email, 'level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, (int)$this->pdo->lastInsertId()]);
        return [$id, $email];
    }

    public function testTeamTokenIsSeparatedFromResidentToken(): void
    {
        $email = 'equipe' . bin2hex(random_bytes(3)) . '@test.local';
        $id = $this->createUser(['email' => $email]);
        $team = $this->sessions()->login($email, 'Senha@123', 'SAC (ios)');
        self::assertSame(201, $team['status']);
        $teamToken = $team['body']['data']['token'];
        self::assertSame('team', $this->pdo->query("SELECT audience FROM erp_resident_app_tokens WHERE user_id = {$id}")->fetchColumn());
        self::assertSame($id, (int)$this->sessions()->authenticate($teamToken)?->id);
        self::assertNull($this->resident()->authenticate($teamToken), 'Token da equipe não entra no app do morador.');

        $residentToken = $this->resident()->login($email, 'Senha@123', 'Morador')['body']['data']['token'];
        self::assertNull($this->sessions()->authenticate($residentToken), 'Token do morador não entra na API da equipe.');
        self::assertFalse($this->sessions()->revoke($residentToken));
        self::assertTrue($this->sessions()->revoke($teamToken));
        self::assertNull($this->sessions()->authenticate($teamToken));
        self::assertNotNull($this->resident()->authenticate($residentToken));
    }

    public function testWithoutErpAccessIsRefused(): void
    {
        $email = 'equipe' . bin2hex(random_bytes(3)) . '@test.local';
        $this->createUser(['email' => $email]);
        $this->team = false;
        $result = $this->sessions()->login($email, 'Senha@123', null);
        self::assertSame([403, 'not_team'], [$result['status'], $result['body']['error']['code']]);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_resident_app_tokens')->fetchColumn());
    }

    public function testApiMiddlewareAcceptsTeamBearerWithoutCsrf(): void
    {
        [$id, $email] = $this->developer();
        $token = TeamAppSessions::make($this->pdo)->login($email, 'Senha@123', null)['body']['data']['token'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        ob_start();
        $ok = (new ApiAuthenticated())->handle(null);
        ob_end_clean();
        self::assertTrue($ok);
        self::assertSame($id, Auth::actingUserId());

        Auth::actingAs(null);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . ResidentAppSessions::newToken();
        ob_start();
        $ok = (new ApiAuthenticated())->handle(null);
        $out = (string)ob_get_clean();
        self::assertFalse($ok);
        self::assertStringContainsString('unauthenticated', $out);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testPushGoesOnlyToUsersWithTheApp(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $push = new TeamPush($this->pdo, static fn (array $messages): array => array_map(static fn (array $m): array => str_contains($m['to'], 'gone') ? ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']] : ['status' => 'ok'], $messages));
        $push->register($a, 'sac', 'ExponentPushToken[aaaaaaaaaaaa]', 'ios');
        $push->register($a, 'sac', 'ExponentPushToken[goneaaaaaaaa]', 'android');
        $push->register($b, 'meu-dia', 'ExponentPushToken[bbbbbbbbbbbb]', 'ios');
        self::assertSame([$a], $push->usersWithDevices('sac'));
        self::assertSame(1, $push->send([$a, $b], 'sac', 'Nova mensagem no chamado SAC202611260001', 'Oi, a lâmpada queimou.', ['path' => '/chamados/7']));
        self::assertSame(0, $push->send($b, 'sac', 'x', 'y'));

        self::assertSame(['mode' => 'expo', 'sent' => 1, 'skipped' => 0, 'failed' => 0, 'tokens_removed' => 1], $push->dispatch(10, 'expo'));
        self::assertSame(['/chamados/7'], array_map(static fn ($r) => json_decode($r, true)['path'], $this->pdo->query("SELECT data_json FROM team_push_queue WHERE status = 'sent'")->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM team_push_devices WHERE user_id = {$a}")->fetchColumn());

        $push->send($a, 'sac', 'Outro', 'Texto');
        self::assertSame(1, $push->dispatch(10, 'log')['skipped']);
        $push->forget($a, 'sac', 'ExponentPushToken[aaaaaaaaaaaa]');
        self::assertSame([], $push->usersWithDevices('sac'));
    }

    public function testInvalidPushInputIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TeamPush($this->pdo))->register($this->createUser(), 'outro', 'ExponentPushToken[aaaaaaaaaaaa]');
    }
}
