<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\OnboardingChecklist;
use Source\Services\OnboardingRenewal;

/** ADR 0219: renovação em lote do checklist pelo OnboardingChecklist::update, tudo ou nada, prévia e auditoria. */
final class OnboardingRenewalTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        (new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-01')))->start($this->condominium);
        $this->pdo->exec("UPDATE condominium_onboarding_items SET status = 'regular', expires_at = '2026-11-01', notes = 'Falar com o Sr. Beltrano'
            WHERE condominium_id = {$this->condominium}");
    }

    public function testRenewsWithCatalogMonthsAndExplicitValidity(): void
    {
        [$extintores, $caixa, $avcb] = [$this->itemId('extintores'), $this->itemId('caixa_agua'), $this->itemId('avcb')];
        $input = ['item_ids' => [$extintores, $caixa], 'renewed_on' => '2026-11-05'];

        $preview = (new OnboardingRenewal($this->pdo))->renew($this->admin, $this->condominium, $input + ['dry_run' => true]);
        self::assertSame([true, 0, ['2027-11-05', '2027-05-05']], [$preview['dry_run'], $preview['changed'], array_column($preview['items'], 'expires_after')]);
        self::assertSame(['2026-11-01', '2026-11-01'], [$this->expiry($extintores), $this->expiry($caixa)], 'dry_run não grava.');
        self::assertStringNotContainsString('Beltrano', (string)json_encode($preview));

        $done = (new OnboardingRenewal($this->pdo))->renew($this->admin, $this->condominium, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['2027-11-05', '2027-05-05'], [$this->expiry($extintores), $this->expiry($caixa)]);
        self::assertSame(2, $this->audits('renew'));
        self::assertSame(2, $this->audits('update'), 'A auditoria do checklist (update) também vale.');
        self::assertSame('regular', (new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-05')))->item($this->condominium, $extintores)['situation']);

        try {
            (new OnboardingRenewal($this->pdo))->renew($this->admin, $this->condominium, ['item_ids' => [$extintores, $avcb], 'renewed_on' => '2026-11-06']);
            self::fail('AVCB sem validade padrão passou sem a nova validade.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$avcb, $exception->errors['_item']);
        }
        self::assertSame('2027-11-05', $this->expiry($extintores), 'Tudo ou nada: o extintor não mudou.');
        self::assertSame(2, $this->audits('renew'));

        (new OnboardingRenewal($this->pdo))->renew($this->admin, $this->condominium, ['item_ids' => [$avcb], 'renewed_on' => '2026-11-06', 'expires_at' => '2031-11-06']);
        self::assertSame('2031-11-06', $this->expiry($avcb));
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new OnboardingRenewal($this->pdo))->renew($limited, $this->condominium, ['item_ids' => [$this->itemId('extintores')], 'renewed_on' => '2026-11-05']);
            self::fail('Renovou fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function itemId(string $code): int
    {
        return (int)$this->pdo->query("SELECT i.id FROM condominium_onboarding_items i JOIN onboarding_item_templates t ON t.id = i.template_id
            WHERE i.condominium_id = {$this->condominium} AND t.code = " . $this->pdo->quote($code))->fetchColumn();
    }

    private function expiry(int $item): string
    {
        return substr((string)$this->pdo->query('SELECT expires_at FROM condominium_onboarding_items WHERE id = ' . $item)->fetchColumn(), 0, 10);
    }

    private function audits(string $action): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'condominium_onboarding_items' AND action = " . $this->pdo->quote($action))
            ->fetchColumn();
    }
}
