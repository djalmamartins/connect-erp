<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Support\SacDesk;

/** Balão do SAC (ADR 0631) no MariaDB de teste: quem aparece online, horário e consentimento. */
final class SacDeskTest extends TestCase
{
    private SacDesk $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = new SacDesk($this->pdo);
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('SAC', 'support.manage', 'Conteúdo')");
    }

    public function testOnlyConsentingAgentsActiveInTheLastMinutesAppearDuringBusinessHours(): void
    {
        $ana = $this->agent('Ana', true);
        $bruno = $this->agent('Bruno', false);
        $carla = $this->agent('Carla', true);
        $resident = (new User())->findById($this->createUser(['first_name' => 'Morador']));
        $now = new DateTimeImmutable('2026-11-25 10:00:00'); // quarta-feira, no horário

        foreach ([$ana, $bruno, $resident] as $user) {
            unset($_SESSION['sacSeenAt']);
            $this->desk->touch($user);
        }
        $this->pdo->prepare('UPDATE users SET sac_seen_at = ? WHERE id IN (?, ?)')->execute(['2026-11-25 09:58:00', $ana->id, $bruno->id]);
        $this->pdo->prepare('UPDATE users SET sac_seen_at = ? WHERE id = ?')->execute(['2026-11-25 09:40:00', $carla->id]);
        self::assertNull($this->seen($resident), 'quem não atende chamados não marca presença');

        $status = $this->desk->status($now, static fn (string $photo): string => '/thumb/' . $photo);
        self::assertTrue($status['open']);
        self::assertSame(['Ana'], array_column($status['agents'], 'name'), 'Bruno não autorizou; Carla saiu há mais de 5 minutos');
        self::assertSame('A', $status['agents'][0]['initials']);
        self::assertSame(['name', 'initials', 'photo'], array_keys($status['agents'][0]), 'o site não recebe e-mail, sobrenome nem id');
        self::assertSame('segunda a sexta, das 8h às 18h', $status['hours']);

        // Fora do horário (noite, fim de semana, feriado nacional) ninguém aparece.
        foreach (['2026-11-25 18:00:00', '2026-11-28 10:00:00', '2026-12-25 10:00:00'] as $closed) {
            $off = $this->desk->status(new DateTimeImmutable($closed));
            self::assertFalse($off['open'], $closed);
            self::assertSame([], $off['agents'], $closed);
        }
    }

    public function testConsentIsChosenByThePersonAndAudited(): void
    {
        $ana = $this->agent('Ana', false);
        self::assertFalse($this->desk->isPublic($ana));
        $this->desk->setPublic($ana, true);
        self::assertTrue($this->desk->isPublic($ana));
        $audit = $this->pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'users' AND entity_id = ?");
        $audit->execute([$ana->id]);
        self::assertGreaterThan(0, (int)$audit->fetchColumn());
        $this->desk->setPublic($ana, false);
        self::assertFalse($this->desk->isPublic($ana));

        // As colunas novas (nulas até a pessoa atender) não podem travar a edição do cadastro.
        $plain = (new User())->findById($this->createUser(['first_name' => 'Sem', 'level' => 5]));
        $plain->password = 'Nova@12345';
        self::assertTrue($plain->save(), (string)$plain->message()?->getText());
    }

    public function testTouchIsThrottledPerSession(): void
    {
        $ana = $this->agent('Ana', true);
        unset($_SESSION['sacSeenAt']);
        $_SESSION['sacSeenAt'] = time();
        $this->desk->touch($ana);
        self::assertNull($this->seen($ana), 'gravou há menos de um minuto: não grava de novo');
    }

    private function agent(string $name, bool $public): User
    {
        $id = $this->createUser(['first_name' => $name, 'level' => 5]);
        $slug = 'sac_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 10)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = 'support.manage'")->execute([$role]);
        $this->pdo->prepare('UPDATE users SET sac_public = ? WHERE id = ?')->execute([$public ? 1 : 0, $id]);
        return (new User())->findById($id);
    }

    private function seen(User $user): ?string
    {
        $stmt = $this->pdo->prepare('SELECT sac_seen_at FROM users WHERE id = ?');
        $stmt->execute([$user->id]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? null : (string)$value;
    }
}
