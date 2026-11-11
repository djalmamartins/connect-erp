<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ObligationCalendar;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\OnboardingChecklist;

/** ADR 0218: calendário de vencimentos (seguro, contrato, mandato e checklist), somente leitura e sem dado pessoal. */
final class ObligationCalendarTest extends TestCase
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

    public function testAllSourcesInOneCalendar(): void
    {
        $supplier = $this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Portaria Teste Ltda', 'document' => '11222333000181', 'category' => 'portaria'])['id'];
        $policy = $this->registry->save($this->admin, 'insurances', ['condominium_id' => $this->condominium, 'insurer' => 'Seguradora Teste', 'policy_number' => 'AP-77',
            'starts_on' => '2025-12-01', 'ends_on' => '2026-11-30'])['id'];
        $contract = $this->registry->save($this->admin, 'contracts', ['condominium_id' => $this->condominium, 'supplier_id' => $supplier, 'object' => 'Portaria 24h',
            'starts_on' => '2025-01-01', 'ends_on' => '2027-01-20', 'monthly_cents' => '1000,00', 'readjustment' => 'ipca'])['id'];
        $this->registry->save($this->admin, 'contracts', ['condominium_id' => $this->condominium, 'supplier_id' => $supplier, 'object' => 'Limpeza',
            'starts_on' => '2025-01-01', 'monthly_cents' => '500,00', 'readjustment' => 'ipca']);
        $term = $this->registry->save($this->admin, 'board', ['condominium_id' => $this->condominium, 'role' => 'sindico', 'name' => 'Fulano Síndico',
            'term_starts_on' => '2025-01-01', 'term_ends_on' => '2026-12-31', 'email' => 'fulano@example.com', 'phone' => '(16) 99999-0000'])['id'];
        $checklist = new OnboardingChecklist($this->pdo, new DateTimeImmutable('2026-11-11'));
        $checklist->start($this->condominium);
        $avcb = (int)$this->pdo->query("SELECT i.id FROM condominium_onboarding_items i JOIN onboarding_item_templates t ON t.id = i.template_id
            WHERE i.condominium_id = {$this->condominium} AND t.code = 'avcb'")->fetchColumn();
        $this->pdo->exec("UPDATE condominium_onboarding_items SET status = 'regular', expires_at = '2026-11-01', notes = 'Ligar para o Sr. Beltrano'
            WHERE id = {$avcb}");
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ObligationCalendar($this->pdo))->build($this->admin, $this->condominium, ObligationCalendar::SOURCES, '2026-11-11');
        self::assertSame([['checklist', $avcb, 'expired'], ['insurances', $policy, '30'], ['board', $term, '60'], ['contracts', $contract, '90']],
            array_map(static fn (array $i): array => [$i['source'], $i['id'], $i['bucket']], $report['items']));
        self::assertSame('document_expiry', $report['items'][0]['kind']);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'fulano@', '99999-0000', 'Beltrano', 'responsible'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $short = (new ObligationCalendar($this->pdo))->build($this->admin, $this->condominium, ['contracts', 'board'], '2026-11-11', 30);
        self::assertSame([[], ['insurances', 'checklist']], [$short['items'], $short['hidden_sources']]);
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ObligationCalendar($this->pdo))->build($limited, $this->condominium, ObligationCalendar::SOURCES);
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
