<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LafayetteSample;
use Source\Support\Access;

/**
 * Condomínio de teste Lafayette (docs/process/LAFAYETTE_TEST_DATA.md): seis meses de operação criados pelos services e, no fim, todas as
 * conferências cruzadas precisam bater (faturas, água, boletos, saldos, prestação de contas, balancete, DRE,
 * fechamento, inadimplência e balanço). Se um relatório deixar de fechar com os outros, este teste quebra.
 */
final class LafayetteSampleIntegrationTest extends TestCase
{
    public function testSixMonthsCloseOnEveryReport(): void
    {
        $admin = $this->admin();
        $sample = new LafayetteSample($this->pdo, $admin);
        $created = $sample->create();

        self::assertSame(16, $created['unidades']);
        self::assertSame(12, $created['faturas'], 'Taxa e fundo de reserva nos 6 meses.');
        self::assertSame(96, $created['leituras'], '16 relógios × 6 meses.');
        self::assertSame(4, $created['fechamentos']);
        self::assertSame(1, $created['acordos']);
        self::assertSame(1, $created['assembleias']);

        $report = $sample->verify();
        $failed = array_filter($report['checks'], static fn (array $check): bool => !$check['ok']);
        self::assertSame([], array_values($failed), 'Conferências que não fecharam.');
        self::assertTrue($report['ok']);
        self::assertGreaterThan(30, count($report['checks']));

        try {
            $sample->create();
            self::fail('Criou o Lafayette duas vezes.');
        } catch (DomainException) {
        }

        // Remover apaga tudo do condomínio (e só dele) e permite criar de novo, com os mesmos números.
        $other = $this->pdo->query("SELECT COUNT(*) FROM erp_suppliers")->fetchColumn();
        $removed = $sample->remove();
        self::assertNull($sample->condominiumId());
        self::assertSame(16, $removed['erp_units']);
        self::assertGreaterThan(0, $removed['erp_invoice_items']);
        foreach (['erp_entries', 'erp_units', 'erp_invoice_items', 'erp_assembly_votes', 'erp_meter_readings', 'erp_bank_charges', 'erp_agreement_originals'] as $table) {
            self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "{$table} sobrou depois de remover.");
        }
        self::assertSame($other, $this->pdo->query("SELECT COUNT(*) FROM erp_suppliers")->fetchColumn(), 'Fornecedores da administradora ficam.');
        $sample->create();
        self::assertTrue($sample->verify()['ok']);
    }

    public function testCompetencesAreTheSixFullMonthsBeforeToday(): void
    {
        self::assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], LafayetteSample::competences('2026-11-11'));
        self::assertSame(['2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12'], LafayetteSample::competences('2026-01-31'));
        self::assertSame(14, strlen(LafayetteSample::cnpj('475120830001')));
    }

    private function admin(): User
    {
        $this->registerMigrationPermissions();
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos os condomínios', '" . CondominiumScope::ALL_PERMISSION . "', 'ERP')");
        $id = $this->createUser(['level' => 10]);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Desenvolvedor', 'developer', 100)");
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT {$role}, id FROM access_permissions");
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        Access::clear();
        return (new User())->findById($id);
    }
}
