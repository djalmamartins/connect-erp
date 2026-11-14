<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BannerDeactivation;
use Source\Services\Erp\BannerOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0287: banners encerrados ainda ativos inativados em lote no MariaDB, com o dia do banco, a auditoria e os de todos os condomínios só para quem acessa todos. */
final class BannerDeactivationTest extends TestCase
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
    }

    public function testDeactivatesOnlyTheEndedOnes(): void
    {
        $ended = $this->banner($this->condominium, -20, -1);
        $showing = $this->banner($this->condominium, -1, 0);
        $global = $this->banner(null, -20, -2);
        $before = $this->audits();

        $preview = (new BannerDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Banners esquecidos', 'dry_run' => true]);
        self::assertSame([[$ended], 1, 0], [array_column($preview['banners'], 'id'), $preview['skipped_global'], $preview['changed']]);
        self::assertSame($before, $this->audits(), 'A prévia não audita.');

        $done = (new BannerDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Banners esquecidos']);
        self::assertSame([[$ended], 1], [array_column($done['banners'], 'id'), $done['changed']]);
        self::assertSame(['inactive', 'active', 'active'], [$this->bannerStatus($ended), $this->bannerStatus($showing), $this->bannerStatus($global)], 'Acabar hoje ainda é estar no ar.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_banners' AND action = 'deactivate' AND entity_id = '{$ended}'")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT deleted_at FROM erp_banners WHERE id = {$ended}")->fetchColumn() ?: null, 'Nada é apagado.');
        $report = (new BannerOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([$global], $report['ended_active_ids']);

        try {
            (new BannerDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Banners esquecidos', 'banner_ids' => [$global]]);
            self::fail('De todos os condomínios sem acesso a todos.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$global, $exception->errors['_banner']);
        }
        self::assertSame('active', $this->bannerStatus($global));

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new BannerDeactivation($this->pdo))->deactivate($limited, ['condominium_id' => $this->condominium, 'reason' => 'Banners esquecidos']);
            self::fail('Fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
    }

    private function bannerStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_banners WHERE id = {$id}")->fetchColumn();
    }

    private function banner(?int $condominium, int $startDays, int $endDays): int
    {
        $this->pdo->prepare("INSERT INTO erp_banners (condominium_id, title, message, starts_on, ends_on, status, created_by)
            VALUES (?, 'Recado', 'Fale com Fulano', DATE_ADD(CURDATE(), INTERVAL ? DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'active', ?)")
            ->execute([$condominium, $startDays, $endDays, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }
}
