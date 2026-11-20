<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\IntegrationTokens;
use Source\Controllers\Api\V1\Talk;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;
use Source\Support\Middleware\TalkApiAuthenticated;

/**
 * Token de integração com escopos, validade, IPs e tela (ADR 0601): o token que já existia continua valendo, cada
 * rota do Talk confere o escopo, o token vencido ou de IP fora da lista cai, e a tela lista sem mostrar o hash.
 */
final class IntegrationTokensTest extends TestCase
{
    private int $alpha;
    private User $agent;
    private mixed $remoteAddr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        foreach (['support.manage' => 'Help Desk', 'erp.access' => 'ERP', 'erp.settings.view' => 'Configurações', 'erp.settings.manage' => 'Configurações'] as $slug => $name) {
            $this->pdo->prepare('INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, ?)')->execute([$name, $slug, 'Teste']);
        }
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 5, 'first_name' => 'Connect Talk', 'last_name' => 'Integração']);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->agent = (new User())->findById($id);
        Access::clear();
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        TalkTokens::setCurrent(null);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($this->remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
        }
        parent::tearDown();
    }

    /** @return array{0: bool, 1: ?array} resultado do middleware e o JSON que ele mandou (se mandou) */
    private function through(string $token): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        ob_start();
        $ok = (new TalkApiAuthenticated())->handle(null);
        $out = (string)ob_get_clean();
        return [$ok, $out !== '' ? json_decode($out, true) : null];
    }

    private function call(callable $action): array
    {
        ob_start();
        $action();
        return (array)json_decode((string)ob_get_clean(), true);
    }

    public function testTerminalTokenKeepsTheLegacyScopesAndTalkRoutesCheckThem(): void
    {
        $tokens = new TalkTokens($this->pdo);
        $legacy = $tokens->create((int)$this->agent->id);
        self::assertSame('people.read,charges.read,tickets.write', (string)$this->pdo->query('SELECT scopes FROM erp_integration_tokens WHERE id = ' . $legacy['id'])->fetchColumn());

        [$ok] = $this->through($legacy['token']);
        self::assertTrue($ok);
        self::assertSame(TalkTokens::LEGACY_SCOPES, TalkTokens::current()['scopes']);
        $me = $this->call(static fn () => (new Talk())->me());
        self::assertSame(TalkTokens::LEGACY_SCOPES, $me['data']['scopes']);
        self::assertSame([['id' => $this->alpha, 'name' => 'Alpha']], $me['data']['condominiums']);
        self::assertNull($me['data']['expires_at']);

        // Token só com condominiums.read não acha pessoa nem abre chamado: 403 insufficient_scope.
        Auth::actingAs(null);
        $readOnly = $tokens->create((int)$this->agent->id, 'Só leitura', null, ['condominiums.read']);
        [$ok] = $this->through($readOnly['token']);
        self::assertTrue($ok);
        $_GET['phones'] = '31999998888';
        $denied = $this->call(static fn () => (new Talk())->people());
        unset($_GET['phones']);
        self::assertSame('insufficient_scope', $denied['error']['code']);
        self::assertSame('people.read', $denied['error']['scope']);
        $closed = $this->call(static fn () => (new Talk())->close(['id' => '1']));
        self::assertSame('insufficient_scope', $closed['error']['code']);
    }

    public function testExpiredTokenAndIpOutsideTheListAreRefused(): void
    {
        $tokens = new TalkTokens($this->pdo);
        $created = $tokens->create((int)$this->agent->id, 'Com IP', null, ['people.read'], '+30 days', '10.0.0.5');
        self::assertNull($tokens->resolve($created['token'], '10.0.0.6'), 'IP fora da lista do token.');
        self::assertNull($tokens->resolve($created['token'], null), 'Sem IP não passa quando o token tem lista.');
        $ok = $tokens->resolve($created['token'], '10.0.0.5');
        self::assertSame(['people.read'], $ok['scopes']);
        self::assertSame('10.0.0.5', (string)$this->pdo->query('SELECT last_ip FROM erp_integration_tokens WHERE id = ' . $created['id'])->fetchColumn());

        $this->pdo->exec('UPDATE erp_integration_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ' . $created['id']);
        self::assertNull($tokens->resolve($created['token'], '10.0.0.5'), 'Vencido.');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        [$ok, $body] = $this->through($created['token']);
        self::assertFalse($ok);
        self::assertSame('unauthenticated', $body['error']['code']);
        self::assertNull(TalkTokens::current());

        try {
            $tokens->create((int)$this->agent->id, 'Passado', null, ['people.read'], '2020-01-01');
            self::fail('Validade no passado tem de dar erro.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('futura', $error->getMessage());
        }
    }

    public function testScreenListsWithoutHashAndRevokes(): void
    {
        $tokens = new TalkTokens($this->pdo);
        $created = $tokens->create((int)$this->agent->id, 'Connect Talk do escritório', (int)$this->agent->id, ['condominiums.read', 'people.read'], '+365 days');
        $plain = $this->createUser(['level' => 1, 'first_name' => 'Sem', 'last_name' => 'Permissão']);
        $eligible = array_column($tokens->eligibleUsers(), 'id');
        self::assertContains((int)$this->agent->id, $eligible);
        self::assertNotContains($plain, $eligible, 'Usuário sem support.manage não pode ser o usuário de integração.');

        // Quem mexe na tela é um administrador do ERP (papel developer libera tudo), não o usuário de integração.
        $admin = $this->createUser(['level' => 10, 'first_name' => 'Admin']);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Developer', 'developer', 100)");
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$admin, (int)$this->pdo->lastInsertId()]);
        Access::clear();
        Auth::actingAs((new User())->findById($admin));
        $list = $this->call(static fn () => (new IntegrationTokens())->index());
        self::assertArrayHasKey("data", $list, (string)json_encode($list));
        $row = $list["data"]["tokens"][0];
        self::assertSame($created['id'], $row['id']);
        self::assertSame(['condominiums.read', 'people.read'], $row['scopes']);
        self::assertSame(substr($created['token'], -4), $row['hint']);
        self::assertNotNull($row['expires_at']);
        $json = json_encode($list);
        self::assertStringNotContainsString($created['token'], (string)$json);
        self::assertStringNotContainsString(hash('sha256', $created['token']), (string)$json);
        self::assertCount(count(TalkTokens::SCOPES), $list['data']['scopes']);

        $audit = (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_integration_tokens' AND entity_id = " . $created['id'])->fetchColumn();
        self::assertGreaterThanOrEqual(1, $audit, 'Criar vai para a auditoria.');

        $revoked = $this->call(static fn () => (new IntegrationTokens())->revoke(['id' => (string)$created['id']]));
        self::assertTrue($revoked['data']['revoked']);
        self::assertNull($tokens->resolve($created['token']));
        $again = $this->call(static fn () => (new IntegrationTokens())->revoke(['id' => (string)$created['id']]));
        self::assertSame('not_found', $again['error']['code']);

        // Quem não tem erp.settings.manage não revoga.
        Auth::actingAs((new User())->findById($plain));
        Access::clear();
        $forbidden = $this->call(static fn () => (new IntegrationTokens())->revoke(['id' => '1']));
        self::assertSame('forbidden', $forbidden['error']['code']);
    }
}
