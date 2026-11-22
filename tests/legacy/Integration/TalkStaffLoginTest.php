<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Environments;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkStaffLogin;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;

/**
 * Login único do Talk (ADR 0359): o código de /ambiente/talk vira o funcionário só com talk.access,
 * uma vez e só para o Talk; o Talk revalida o acesso pelo id.
 */
final class TalkStaffLoginTest extends TestCase
{
    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Atendimento por WhatsApp (Connect Talk)', 'talk.access', 'Atendimento')");
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Atendente', 'attendant_talk', 10)");
        $this->role = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT {$this->role}, id FROM access_permissions WHERE slug = 'talk.access'");
        Access::clear();
    }

    protected function tearDown(): void
    {
        Access::clear();
        $_SESSION = [];
        parent::tearDown();
    }

    private function staff(bool $withRole, ?string $override = null, int $level = 1): User
    {
        $id = $this->createUser(['level' => $level, 'first_name' => 'Ana', 'last_name' => 'Atendente', 'email' => 'Ana' . bin2hex(random_bytes(3)) . '@Connect.test']);
        $role = $withRole ? $this->role : (int)$this->pdo->query("INSERT INTO access_roles (name, slug, level) VALUES ('Sem Talk', 'no_talk_" . $id . "', 10) RETURNING id")->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        if ($override !== null) {
            $this->pdo->prepare("INSERT INTO access_user_overrides (user_id, permission_id, effect) SELECT ?, id, ? FROM access_permissions WHERE slug = 'talk.access'")->execute([$id, $override]);
        }
        Access::clear();
        return (new User())->findById($id);
    }

    private function code(User $user, string $key = 'talk'): string
    {
        return (new Environments($this->pdo))->issue($user, $key);
    }

    public function testCodeBecomesTheEmployeeOnlyOnce(): void
    {
        $user = $this->staff(true);
        $code = $this->code($user);
        $login = new TalkStaffLogin(new Environments($this->pdo));
        $who = $login->exchange($code);
        self::assertSame((int)$user->id, $who['id']);
        self::assertSame('Ana Atendente', $who['name']);
        self::assertSame(mb_strtolower((string)$user->email), $who['email'], 'E-mail em minúsculas para o Talk achar o perfil.');
        $this->expectException(TicketError::class);
        $login->exchange($code);
    }

    public function testRejectsWithoutTalkAccessOrWrongEnvironment(): void
    {
        $login = new TalkStaffLogin(new Environments($this->pdo));
        $cases = [
            'sem permissão no papel' => $this->code($this->staff(false)),
            'negado no cadastro do usuário' => $this->code($this->staff(true, 'deny')),
            'sem papel, nível alto (talk. é estrito)' => $this->code($this->noRole()),
            'código de outro ambiente' => $this->code($this->staff(true), 'erp'),
            'código malformado' => 'abc',
        ];
        foreach ($cases as $case => $code) {
            try {
                $login->exchange($code);
                self::fail('Deveria recusar: ' . $case);
            } catch (TicketError $error) {
                self::assertSame(404, $error->status(), $case);
            }
        }
        // Liberado por usuário, mesmo com papel sem a permissão.
        self::assertNotEmpty($login->exchange($this->code($this->staff(false, 'allow'))));
    }

    public function testStatusFollowsThePermission(): void
    {
        $user = $this->staff(true);
        $login = new TalkStaffLogin(new Environments($this->pdo));
        self::assertTrue($login->status((int)$user->id)['active']);
        $this->pdo->prepare("INSERT INTO access_user_overrides (user_id, permission_id, effect) SELECT ?, id, 'deny' FROM access_permissions WHERE slug = 'talk.access'")->execute([(int)$user->id]);
        Access::clear();
        self::assertFalse($login->status((int)$user->id)['active'], 'Tirou o acesso no ERP: o Talk encerra a sessão.');
        $this->pdo->prepare("UPDATE users SET status = 'trash' WHERE id = ?")->execute([(int)$user->id]);
        Access::clear();
        self::assertFalse($login->status((int)$user->id)['active']);
    }

    public function testScopeAndRoutes(): void
    {
        self::assertArrayHasKey('staff.login', TalkTokens::SCOPES);
        self::assertNotContains('staff.login', TalkTokens::LEGACY_SCOPES, 'Token antigo não ganha login sem o Djalma marcar o escopo.');
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertStringContainsString('$route->post("/talk/staff/login", "Talk:staffLogin", null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
        self::assertStringContainsString('$route->get("/talk/staff/{id}", "Talk:staffStatus", null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
    }

    public function testReturnAfterLoginIsOnlyTheTalkSwitch(): void
    {
        Environments::rememberReturn('erp');
        self::assertNull(Environments::takeReturn(), 'Só o Talk guarda a volta.');
        Environments::rememberReturn('talk');
        self::assertSame('/ambiente/talk', Environments::takeReturn());
        self::assertNull(Environments::takeReturn(), 'Uma vez só.');
    }

    public function testTalkSwitchWithoutSessionGoesToErpLoginAndRemembersTheWayBack(): void
    {
        \Source\Models\Auth::actingAs(null);
        try {
            (new \Source\Controllers\Environments\Handoff())->go(['environment' => 'talk']);
            self::fail('Deveria redirecionar.');
        } catch (\Source\Support\RedirectIntercepted $redirect) {
            self::assertStringEndsWith('/login', $redirect->location, 'Sem sessão: login do ERP, não o Talk (que não tem login próprio).');
        }
        self::assertSame('/ambiente/talk', Environments::takeReturn());
    }

    public function testTalkUrlPointsTheSwitchToThePi(): void
    {
        self::assertSame(['scheme' => 'https', 'host' => 'talk.connect.com.br'], Environments::talkOrigin('https://talk.connect.com.br/'));
        self::assertSame(['scheme' => 'http', 'host' => '10.0.0.96:8080'], Environments::talkOrigin('http://10.0.0.96:8080'));
        foreach (['', 'ftp://talk.x', 'https://u:p@talk.x', 'https://talk.x/caminho', 'https://talk.x/?a=1', 'javascript:alert(1)'] as $bad) {
            self::assertNull(Environments::talkOrigin($bad), $bad);
        }
    }

    private function noRole(): User
    {
        $id = $this->createUser(['level' => 10]);
        Access::clear();
        return (new User())->findById($id);
    }
}
