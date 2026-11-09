<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use PDO;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentInvitationAcceptance as Acceptance;
use Source\Services\Erp\ResidentInvitations;

/** Aceite do convite (ADR 0092): conta criada uma vez, token vencido, documento que não confere e corrida entre duas conexões. */
final class ResidentInvitationAcceptanceIntegrationTest extends TestCase
{
    private int $resident;
    private string $cpf;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['erp_resident_invitations', 'auth_throttle_events'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $alpha = (int)$this->pdo->lastInsertId();
        $adminId = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($adminId, $alpha);
        $admin = (new User())->findById($adminId);
        $registry = new RegistryService($this->pdo);
        $unit = $registry->save($admin, 'units', ['condominium_id' => $alpha, 'number' => '201', 'type' => 'apartamento'])['id'];
        $this->cpf = self::validCpf();
        $this->resident = (int)$registry->save($admin, 'residents', ['condominium_id' => $alpha, 'unit_id' => $unit, 'relationship' => 'owner',
            'name' => 'Duda Moradora Silva', 'document' => $this->cpf, 'email' => 'duda' . bin2hex(random_bytes(3)) . '@morador.test'])['id'];
        $this->alpha = $alpha;
    }

    private int $alpha;

    public function testAcceptCreatesOneAccountOnlyOnce(): void
    {
        $token = $this->invitation('+7 days');
        $service = new Acceptance($this->pdo);
        $preview = $service->preview($token);
        self::assertSame('Duda Moradora Silva', $preview['name']);
        self::assertStringContainsString('**@morador.test', $preview['email']);

        self::assertSame(Acceptance::MISMATCH, $service->accept($token, self::validCpf(), 'SenhaBoa123', 'SenhaBoa123')['message'], 'CPF diferente do cadastro.');
        $ok = $service->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123', '198.51.100.7');
        self::assertTrue($ok['ok']);
        $user = $this->pdo->query('SELECT level, password, first_name, last_name FROM users WHERE id = ' . (int)$ok['user_id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['1', 'Duda', 'Moradora Silva'], [(string)$user['level'], $user['first_name'], $user['last_name']]);
        self::assertTrue(password_verify('SenhaBoa123', $user['password']));
        self::assertSame($ok['user_id'], (int)$this->pdo->query('SELECT user_id FROM erp_residents WHERE id = ' . $this->resident)->fetchColumn());

        self::assertSame(['ok' => false, 'message' => Acceptance::GENERIC], $service->accept($token, $this->cpf, 'OutraSenha9', 'OutraSenha9'), 'Uso único.');
        self::assertNull($service->preview($token));
        $audit = (string)$this->pdo->query("SELECT GROUP_CONCAT(context_json) FROM system_audit_logs WHERE entity IN ('erp_resident_invitations', 'users')")->fetchColumn();
        self::assertStringNotContainsString($token, $audit, 'O token não vai para a auditoria.');
    }

    public function testExpiredAndThrottled(): void
    {
        $expired = $this->invitation('-1 minute');
        $service = new Acceptance($this->pdo);
        self::assertNull($service->preview($expired));
        self::assertSame(Acceptance::GENERIC, $service->accept($expired, $this->cpf, 'SenhaBoa123', 'SenhaBoa123')['message'], 'Vencido: a mesma mensagem.');

        $token = $this->invitation('+7 days');
        for ($i = 0; $i < Acceptance::LIMITS['token'][0]; $i++) {
            $service->accept($token, self::validCpf(), 'SenhaBoa123', 'SenhaBoa123');
        }
        self::assertSame(Acceptance::THROTTLED, $service->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123')['message'], 'Depois de 5 erros, nem o certo passa.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents WHERE user_id IS NOT NULL')->fetchColumn());
    }

    public function testConcurrentAcceptIsSerialized(): void
    {
        $token = $this->invitation('+7 days');
        $other = new PDO('mysql:host=' . CONF_DB_HOST . ';port=' . CONF_DB_PORT . ';dbname=' . CONF_DB_NAME . ';charset=utf8mb4', CONF_DB_USER, CONF_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->beginTransaction();
        $other->prepare('SELECT id FROM erp_resident_invitations WHERE token_hash = ? FOR UPDATE')->execute([hash('sha256', $token)]);
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = (new Acceptance($this->pdo))->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123');
        self::assertFalse($blocked['ok'], 'Enquanto a outra transação segura o convite, este pedido não cria conta.');
        // A outra transação consome o convite e confirma.
        $other->prepare("UPDATE erp_resident_invitations SET status = 'accepted', accepted_at = NOW() WHERE token_hash = ?")->execute([hash('sha256', $token)]);
        $other->commit();
        self::assertSame(Acceptance::GENERIC, (new Acceptance($this->pdo))->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123')['message']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents WHERE user_id IS NOT NULL')->fetchColumn(), 'Nenhuma conta ligada.');
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
    }

    /** Convite pendente com token conhecido (o envio real gera o token na entrega, ADR 0090/0111). */
    private function invitation(string $expires): string
    {
        $token = ResidentInvitations::newToken();
        $this->pdo->prepare('INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$this->alpha, $this->resident, hash('sha256', $token), date('Y-m-d H:i:s', strtotime($expires))]);
        return $token;
    }
}
