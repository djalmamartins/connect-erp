<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\TaxOverrideCleanup;

/** ADR 0293: alíquotas próprias que repetem o padrão inativadas em lote no MariaDB, com as travas de linha, a auditoria só quando grava e o padrão intocado. */
final class TaxOverrideCleanupTest extends TestCase
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

    public function testDeactivatesOnlyTheRedundantKind(): void
    {
        $kinds = array_keys((array)RegistryService::definition('taxes')['fields']['kind']['options']);
        [$same, $other] = [$kinds[0], $kinds[1]];
        $default = $this->tax(null, $same, '5.00');
        $copy = $this->tax($this->condominium, $same, '5.00');
        $this->tax(null, $other, '1.50');
        $different = $this->tax($this->condominium, $other, '2.00');
        $before = $this->audits();

        $preview = (new TaxOverrideCleanup($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Voltar ao padrão', 'dry_run' => true]);
        self::assertSame([[$same], 0], [array_column($preview['kinds'], 'kind'), $preview['changed']]);
        self::assertSame([$before, 'active'], [$this->audits(), $this->taxStatus($copy)], 'A prévia não grava nem audita.');

        try {
            (new TaxOverrideCleanup($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Voltar ao padrão', 'kinds' => [$same, $other]]);
            self::fail('O imposto diferente do padrão passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame($other, $exception->errors['_kind']);
        }
        self::assertSame('active', $this->taxStatus($copy), 'Tudo ou nada.');

        $done = (new TaxOverrideCleanup($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Voltar ao padrão']);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active'], [$this->taxStatus($copy), $this->taxStatus($default), $this->taxStatus($different)]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_taxes' AND action = 'update' AND entity_id = '{$copy}'")
            ->fetchColumn());

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new TaxOverrideCleanup($this->pdo))->deactivate($limited, ['condominium_id' => $this->condominium, 'reason' => 'Voltar ao padrão']);
            self::fail('Fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
    }

    private function taxStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_taxes WHERE id = {$id}")->fetchColumn();
    }

    private function tax(?int $condominium, string $kind, string $rate): int
    {
        $this->pdo->prepare("INSERT INTO erp_taxes (condominium_id, kind, name, rate, applies_to, status, created_by) VALUES (?, ?, 'Alíquota do Fulano', ?, 'servicos', 'active', ?)")
            ->execute([$condominium, $kind, $rate, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }
}
