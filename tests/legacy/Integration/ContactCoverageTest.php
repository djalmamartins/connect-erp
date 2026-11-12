<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContactCoverage;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0252: cobertura dos contatos úteis com os contatos do cadastro e os compartilhados; somente leitura e sem dado do contato. */
final class ContactCoverageTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testCoverageFromTheRegistry(): void
    {
        $this->contact('encanador', 'Encanador Teste', 'encanador@example.invalid');
        $electric = $this->contact('eletricista', 'Elétrica Teste', null);
        $this->pdo->prepare("UPDATE erp_useful_contacts SET status = 'inactive' WHERE id = ?")->execute([$electric]);
        $this->pdo->exec("INSERT INTO erp_useful_contacts (condominium_id, service, name, phone) VALUES (NULL, 'emergencia', 'Bombeiros', '193')");
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ContactCoverage($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['own' => 1, 'shared' => 1, 'inactive_own' => 1, 'with_email' => 1], $report['totals']);
        self::assertSame([2, ['eletricista', 'gas']], [$report['covered_services'], $report['missing_essential']]);
        self::assertSame([true, true, false], array_slice(array_column($report['rows'], 'covered'), 0, 3));
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Encanador Teste', 'Bombeiros', 'example.invalid', '16994007100'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ContactCoverage($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function contact(string $service, string $name, ?string $email): int
    {
        return (int)$this->registry->save($this->admin, 'contacts', ['condominium_id' => $this->condominium, 'service' => $service, 'name' => $name,
            'phone' => '(16) 99400-7100', 'email' => $email])['id'];
    }
}
