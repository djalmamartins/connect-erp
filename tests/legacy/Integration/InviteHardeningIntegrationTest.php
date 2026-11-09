<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvitationRules;
use Source\Services\Erp\InviteThrottle;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentInvitationAcceptance as Acceptance;
use Source\Services\Erp\ResidentInvitations;

/** Endurecimento do convite (ADR 0093): tabela do limite indisponível e documento apagado depois do convite. */
final class InviteHardeningIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $resident;
    private string $cpf;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['erp_resident_invitations', 'auth_throttle_events'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->admin = (new User())->findById($id);
        $registry = new RegistryService($this->pdo);
        $unit = $registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '301', 'type' => 'apartamento'])['id'];
        $this->cpf = self::validCpf();
        $this->resident = (int)$registry->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'owner',
            'name' => 'Eva Moradora', 'document' => $this->cpf, 'email' => 'eva' . bin2hex(random_bytes(3)) . '@morador.test'])['id'];
    }

    public function testUnavailableThrottleTableRefuses(): void
    {
        $token = $this->invitation();
        $this->pdo->exec('RENAME TABLE auth_throttle_events TO auth_throttle_events_off');   // só no banco de teste
        try {
            $result = (new Acceptance($this->pdo))->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123', '198.51.100.20');
            self::assertSame(['ok' => false, 'message' => InviteThrottle::UNAVAILABLE_MESSAGE], $result);
            self::assertSame(InviteThrottle::UNAVAILABLE, (new InviteThrottle($this->pdo))->check(InviteThrottle::subjects('198.51.100.20', null)), 'Abrir também recusa.');
        } finally {
            $this->pdo->exec('RENAME TABLE auth_throttle_events_off TO auth_throttle_events');
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents WHERE user_id IS NOT NULL')->fetchColumn(), 'Nenhuma conta criada.');
        $log = (string)$this->pdo->query("SELECT GROUP_CONCAT(CONCAT(msg, COALESCE(context_json, ''), COALESCE(url, ''))) FROM app_log WHERE event_type LIKE 'invite%'")->fetchColumn();
        self::assertStringNotContainsString($token, $log, 'O token não vai para o log.');
    }

    public function testDocumentRemovedAfterInvitationIsRefused(): void
    {
        $token = $this->invitation();
        $this->pdo->prepare('UPDATE erp_residents SET document = NULL WHERE id = ?')->execute([$this->resident]);
        $result = (new Acceptance($this->pdo))->accept($token, $this->cpf, 'SenhaBoa123', 'SenhaBoa123');
        self::assertSame(['ok' => false, 'message' => Acceptance::GENERIC], $result, 'Sem documento no cadastro: mensagem genérica.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents WHERE user_id IS NOT NULL')->fetchColumn());

        if (in_array(ResidentInvitations::EVENT, NotificationOutbox::EVENTS, true)) {
            try {
                (new ResidentInvitations($this->pdo))->invite($this->admin, $this->alpha, [$this->resident]);
                self::fail('Convidou morador sem documento.');
            } catch (InvalidArgumentException $error) {   // 422 no controller
                self::assertStringStartsWith(InvitationRules::NO_DOCUMENT, $error->getMessage());
            }
        }
    }

    private function invitation(): string
    {
        $token = ResidentInvitations::newToken();
        $this->pdo->prepare('INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))')
            ->execute([$this->alpha, $this->resident, hash('sha256', $token)]);
        return $token;
    }
}
