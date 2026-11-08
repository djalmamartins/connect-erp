<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Services\OnboardingChecklist;
use Source\Services\OnboardingItemNotFound;
use Source\Services\OnboardingValidationError;

/** Checklist de chegada do condomínio (ADR 0013). */
final class OnboardingChecklistTest extends TestCase
{
    private const TODAY = '2026-11-08';

    public function testStartCopiesActiveCatalogOnceAndIsIdempotent(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $active = (int)$this->pdo->query('SELECT COUNT(*) FROM onboarding_item_templates WHERE active = 1')->fetchColumn();

        self::assertGreaterThanOrEqual(20, $active, 'Catálogo inicial semeado pela migration.');
        self::assertFalse($service->started($condo));
        self::assertSame($active, $service->start($condo));
        self::assertSame(0, $service->start($condo), 'Segunda chamada não duplica itens.');
        self::assertTrue($service->started($condo));

        $items = $service->items($condo);
        self::assertCount($active, $items);
        self::assertSame('documentacao', $items[0]['category']);
        self::assertSame('cnpj', $items[0]['code']);
        self::assertContains('avcb', array_column($items, 'code'));
        self::assertContains('extintores', array_column($items, 'code'));
        self::assertSame(['pending'], array_values(array_unique(array_column($items, 'status'))));

        $summary = $service->summary($condo);
        self::assertSame($active, $summary['total']);
        self::assertSame($active, $summary['pending']);
        self::assertSame(0, $summary['progress']);
    }

    public function testActionRequiredGeneratesProtocolAndDefaultDueDate(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $service->start($condo);
        $cnpj = $this->itemByCode($service, $condo, 'cnpj');

        $item = $service->update($condo, $cnpj['id'], ['status' => 'action_required', 'notes' => 'Condomínio sem CNPJ, abrir na Receita.']);

        self::assertSame(sprintf('CHK-2026-%06d', $cnpj['id']), $item['protocol']);
        self::assertSame('2026-12-08', $item['due_date'], 'Prazo padrão de 30 dias do catálogo.');
        self::assertSame('action_required', $item['situation']);

        $again = $service->update($condo, $cnpj['id'], ['status' => 'in_progress']);
        self::assertSame($item['protocol'], $again['protocol'], 'Protocolo não muda depois de gerado.');

        $audit = (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'condominium_onboarding_items' AND action = 'update'")->fetchColumn();
        self::assertSame(2, $audit);
    }

    public function testRegularUsesRenewalPeriodAndBecomesExpiringThenExpired(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $service->start($condo);
        $extintores = $this->itemByCode($service, $condo, 'extintores');

        $item = $service->update($condo, $extintores['id'], ['status' => 'regular']);
        self::assertSame('2027-11-08', $item['expires_at'], 'Sem validade informada, usa a renovação de 12 meses.');
        self::assertSame('regular', $item['situation']);
        self::assertNotNull($item['resolved_at']);

        self::assertSame('expiring', $this->service('2027-10-20')->item($condo, $extintores['id'])['situation']);
        self::assertSame('expired', $this->service('2027-11-09')->item($condo, $extintores['id'])['situation']);

        $informed = $service->update($condo, $extintores['id'], ['expires_at' => '2027-03-31']);
        self::assertSame('2027-03-31', $informed['expires_at'], 'Validade informada prevalece.');
    }

    public function testOverdueProtocolAndSummary(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $service->start($condo);
        $avcb = $this->itemByCode($service, $condo, 'avcb');
        $regimento = $this->itemByCode($service, $condo, 'regimento');
        $service->update($condo, $avcb['id'], ['status' => 'action_required', 'due_date' => '2026-11-10']);
        $service->update($condo, $regimento['id'], ['status' => 'not_applicable']);

        $later = $this->service('2026-11-20');
        self::assertSame('overdue', $later->item($condo, $avcb['id'])['situation']);
        $summary = $later->summary($condo);
        self::assertSame(1, $summary['open']);
        self::assertSame(1, $summary['done']);
        self::assertSame(1, $summary['by_situation']['overdue']);
    }

    public function testItemsAreIsolatedByCondominium(): void
    {
        $alpha = $this->condominium('Alpha');
        $beta = $this->condominium('Beta');
        $service = $this->service();
        $service->start($alpha);
        $service->start($beta);
        $alphaItem = $service->items($alpha)[0];

        $this->expectException(OnboardingItemNotFound::class);
        $service->update($beta, $alphaItem['id'], ['status' => 'regular']);
    }

    public function testValidationRejectsInvalidInputWithoutChanges(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $service->start($condo);
        $item = $service->items($condo)[0];

        try {
            $service->update($condo, $item['id'], ['status' => 'feito', 'due_date' => '2026-02-30', 'responsible_user_id' => 999999]);
            self::fail('Entrada inválida foi aceita.');
        } catch (OnboardingValidationError $error) {
            self::assertEqualsCanonicalizing(['status', 'due_date', 'responsible_user_id'], array_keys($error->errors));
        }
        self::assertSame('pending', $service->item($condo, $item['id'])['status']);
    }

    public function testResponsibleAndCustomItem(): void
    {
        $condo = $this->condominium('Alpha');
        $service = $this->service();
        $service->start($condo);
        $userId = $this->createUser(['first_name' => 'Ana', 'last_name' => 'Souza', 'level' => 5]);
        $item = $service->items($condo)[0];

        $updated = $service->update($condo, $item['id'], ['responsible_user_id' => (string)$userId], $userId);
        self::assertSame(['id' => $userId, 'name' => 'Ana Souza'], $updated['responsible']);
        self::assertContains(['id' => $userId, 'name' => 'Ana Souza'], $service->responsibleOptions());

        $custom = $service->addCustom($condo, 'manutencao', 'Portão da garagem sem manutenção', $userId);
        self::assertTrue($custom['custom']);
        self::assertSame('action_required', $custom['status']);
        self::assertNotNull($custom['protocol']);

        $this->expectException(OnboardingValidationError::class);
        $service->addCustom($condo, 'outra', '');
    }

    private function service(string $today = self::TODAY): OnboardingChecklist
    {
        return new OnboardingChecklist($this->pdo, new DateTimeImmutable($today));
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
