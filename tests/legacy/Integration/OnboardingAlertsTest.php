<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Services\CondominiumScope;
use Source\Services\OnboardingAlerts;
use Source\Services\OnboardingChecklist;

/** Avisos de vencimento do checklist de chegada (ADR 0013). */
final class OnboardingAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO notifications_categories (title, uri, description, type) VALUES ('Studio', 'studio', 'Avisos', 'system')");
    }

    public function testResponsibleIsAlertedOncePerSituation(): void
    {
        $condo = $this->condominium('Residencial Aurora');
        $responsible = $this->createUser(['first_name' => 'Ana', 'last_name' => 'Souza', 'email' => 'ana@test.local']);
        $checklist = new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-08'));
        $checklist->start($condo);
        $extintores = $this->itemByCode($checklist, $condo, 'extintores');
        $checklist->update($condo, $extintores['id'], ['status' => 'regular', 'expires_at' => '2026-11-30', 'responsible_user_id' => $responsible]);

        $first = (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-08')))->run();
        self::assertSame(['items' => 1, 'notifications' => 1, 'emails' => 1], $first);
        $body = (string)$this->pdo->query('SELECT body FROM notifications')->fetchColumn();
        self::assertStringContainsString('Extintores: recarga e inspeção do Residencial Aurora vence em 30/11/2026', $body);
        $mail = $this->pdo->query('SELECT recipient_email, subject, body FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('ana@test.local', $mail['recipient_email']);
        self::assertStringStartsWith('Vence em breve: Extintores', $mail['subject']);
        self::assertStringContainsString("/erp/condominios/{$condo}/checklist#item-{$extintores['id']}", $mail['body']);

        $again = (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-09')))->run();
        self::assertSame(0, $again['items'], 'Mesma situação não repete o aviso.');

        $expired = (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-12-01')))->run();
        self::assertSame(1, $expired['items'], 'Ao vencer, avisa de novo com a nova situação.');
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testOverdueProtocolWithoutResponsibleGoesToManagersInScope(): void
    {
        $condo = $this->condominium('Residencial Aurora');
        $other = $this->condominium('Outro');
        $manager = $this->manager();
        $outsider = $this->manager();
        $scope = new CondominiumScope($this->pdo);
        $scope->grant($manager, $condo);
        $scope->grant($outsider, $other);
        $checklist = new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-08'));
        $checklist->start($condo);
        $avcb = $this->itemByCode($checklist, $condo, 'avcb');
        $checklist->update($condo, $avcb['id'], ['status' => 'action_required', 'due_date' => '2026-11-10']);

        self::assertSame(0, (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-10')))->run()['items'], 'Prazo no dia ainda não venceu.');
        $result = (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-11')))->run();

        self::assertSame(1, $result['items']);
        $recipients = array_map('intval', $this->pdo->query('SELECT users_id FROM notifications')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$manager], $recipients, 'Só quem gerencia e tem acesso ao condomínio.');
        self::assertStringContainsString(sprintf('CHK-2026-%06d', $avcb['id']), (string)$this->pdo->query('SELECT body FROM notifications')->fetchColumn());
    }

    public function testUpdatingItemResetsAlertControl(): void
    {
        $condo = $this->condominium('Residencial Aurora');
        $responsible = $this->createUser();
        $checklist = new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-08'));
        $checklist->start($condo);
        $item = $this->itemByCode($checklist, $condo, 'seguro_predial');
        $checklist->update($condo, $item['id'], ['status' => 'regular', 'expires_at' => '2026-11-01', 'responsible_user_id' => $responsible]);
        (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-08')))->run();
        self::assertSame('expired', $checklist->item($condo, $item['id'])['alerted_situation']);

        $checklist->update($condo, $item['id'], ['expires_at' => '2027-11-01']);
        self::assertNull($checklist->item($condo, $item['id'])['alerted_situation']);
        self::assertSame(0, (new OnboardingAlerts($this->pdo, new DateTimeImmutable('2026-11-08')))->run()['items']);
    }

    private function manager(): int
    {
        $userId = $this->createUser();
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level) VALUES ('Gestor', 'gestor', 30)");
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Gerenciar condomínios', 'erp.condominiums.manage', 'ERP')");
        $this->pdo->exec("INSERT IGNORE INTO access_role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM access_roles r JOIN access_permissions p WHERE r.slug = 'gestor' AND p.slug = 'erp.condominiums.manage'");
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'gestor'")->execute([$userId]);
        return $userId;
    }

    /** @return array<string, mixed> */
    private function itemByCode(OnboardingChecklist $service, int $condo, string $code): array
    {
        foreach ($service->items($condo) as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }
        self::fail("Item {$code} não encontrado.");
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }
}
