<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ImportDiscard;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\ImportsOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0285: prévias paradas descartadas com a migration 20261114_0001 aplicada, auditoria e o Imports de verdade recusando aplicar a descartada. */
final class ImportDiscardTest extends TestCase
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

    public function testDiscardsStalePreviews(): void
    {
        $kinds = array_keys(Imports::KINDS);
        $stale = $this->import($kinds[0], 'previewed', 45);
        $recent = $this->import($kinds[0], 'previewed', 2);
        $applied = $this->import($kinds[count($kinds) - 1], 'applied', 90);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
        $service = new ImportDiscard($this->pdo);
        $reason = ['condominium_id' => $this->condominium, 'reason' => 'Prévias esquecidas'];

        $preview = $service->discard($this->admin, $reason + ['dry_run' => true]);
        self::assertSame([[$stale], 0], [array_column($preview['imports'], 'id'), $preview['changed']]);
        self::assertSame('previewed', $this->importStatus($stale), 'A prévia não descarta.');
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn());

        foreach ([[$recent, 'mais nova'], [$applied, 'não está em prévia']] as [$import, $message]) {
            try {
                $service->discard($this->admin, $reason + ['import_ids' => [$stale, $import]]);
                self::fail("Importação {$import} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$import, $exception->errors['_import']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertSame('previewed', $this->importStatus($stale), 'Tudo ou nada.');
        }

        $result = $service->discard($this->admin, $reason);
        self::assertSame([1, 1], [$result['count'], $result['changed']]);
        $row = $this->pdo->query("SELECT status, discarded_by, discard_reason, discarded_at, storage_path FROM erp_imports WHERE id = {$stale}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['discarded', (int)$this->admin->id, 'Prévias esquecidas'], [$row['status'], (int)$row['discarded_by'], $row['discard_reason']]);
        self::assertNotNull($row['discarded_at']);
        self::assertSame('storage/private/imports/x.csv', $row['storage_path'], 'O arquivo fica.');
        self::assertSame('previewed', $this->importStatus($recent));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_imports' AND action = 'discard' AND entity_id = {$stale}")->fetchColumn());
        try {
            (new Imports($this->pdo))->apply($this->admin, $stale);
            self::fail('Aplicou uma prévia descartada.');
        } catch (DomainException $error) {
            self::assertStringContainsString('descartada', $error->getMessage());
        }
        $report = (new ImportsOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([1, 1, 1], [$report['totals']['discarded'], $report['totals']['previewed'], $report['totals']['applied']], 'O relatório da 0284 conta a descartada.');
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['planilha-fulano', 'storage/private', 'hash-teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, $service->discard($this->admin, $reason)['count'], 'Rodar de novo não muda nada.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $service->discard($limited, $reason);
            self::fail('Descarte fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function importStatus(int $import): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_imports WHERE id = {$import}")->fetchColumn();
    }

    private function import(string $kind, string $status, int $daysAgo): int
    {
        $this->pdo->prepare("INSERT INTO erp_imports (condominium_id, kind, original_name, sha256, storage_path, size_bytes, status, rows_count, error_count, created_by,
                created_at, applied_at)
            VALUES (?, ?, 'planilha-fulano.csv', ?, 'storage/private/imports/x.csv', 100, ?, 3, 0, ?, DATE_SUB(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$this->condominium, $kind, hash('sha256', 'hash-teste' . bin2hex(random_bytes(6))), $status, $this->admin->id, $daysAgo,
                $status === 'applied' ? date('Y-m-d H:i:s') : null]);
        return (int)$this->pdo->lastInsertId();
    }
}
