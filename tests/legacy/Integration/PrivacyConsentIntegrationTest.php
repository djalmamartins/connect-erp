<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Services\Privacy\ConsentLog;
use Source\Services\Privacy\LegalDocuments;
use Source\Support\Proposal\ProposalService;

/**
 * Prova dos aceites e consentimentos (LGPD art. 8º, § 2º; ADR 0603).
 */
final class PrivacyConsentIntegrationTest extends TestCase
{
    private function proposal(array $extra = []): array
    {
        return array_merge([
            'name' => 'Pessoa de Teste', 'email' => 'Pessoa@Exemplo.test', 'whatsapp' => '(31) 99999-0000',
            'condominio' => 'Condomínio Fictício', 'units' => '40', 'profile' => 'sindico', 'message' => 'Quero uma proposta.',
        ], $extra);
    }

    public function testProposalWithoutPrivacyCheckboxIsRefusedAndNothingIsSaved(): void
    {
        $result = (new ProposalService())->submit($this->proposal());
        self::assertFalse($result['success']);
        self::assertStringContainsString('Política de Privacidade', $result['message']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM privacy_consents')->fetchColumn());
    }

    public function testProposalRecordsPrivacyAndMarketingSeparately(): void
    {
        $result = (new ProposalService())->submit($this->proposal(['privacy' => '1']));
        self::assertTrue($result['success'], $result['message']);
        $row = $this->pdo->query('SELECT id, consent_at, marketing_consent_at FROM proposals')->fetch(\PDO::FETCH_ASSOC);
        self::assertNotNull($row['consent_at']);
        self::assertNull($row['marketing_consent_at'], 'marketing desmarcado não vira consentimento');
        $rows = $this->pdo->query('SELECT purpose, granted, version, channel, proposal_id, email, request_hash FROM privacy_consents ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame(['privacy', '1', LegalDocuments::PRIVACY, 'proposta', (string)$row['id'], 'pessoa@exemplo.test'],
            [$rows[0]['purpose'], (string)$rows[0]['granted'], $rows[0]['version'], $rows[0]['channel'], (string)$rows[0]['proposal_id'], $rows[0]['email']]);
        self::assertSame(['marketing', '0'], [$rows[1]['purpose'], (string)$rows[1]['granted']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$rows[0]['request_hash'], 'só o hash, nunca o IP');

        (new ProposalService())->submit($this->proposal(['privacy' => '1', 'marketing' => '1', 'email' => 'outra@exemplo.test']));
        $last = $this->pdo->query("SELECT p.marketing_consent_at, c.granted FROM proposals p JOIN privacy_consents c ON c.proposal_id = p.id AND c.purpose = 'marketing' WHERE p.email = 'outra@exemplo.test'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotNull($last['marketing_consent_at']);
        self::assertSame('1', (string)$last['granted']);
    }

    public function testConsentLogKeepsHistoryAndLatestWins(): void
    {
        $userId = $this->createUser();
        $log = new ConsentLog($this->pdo);
        $log->record('marketing', true, '2026-11-20', 'app', ['user_id' => $userId]);
        $log->record('marketing', false, '2026-11-20', 'app', ['user_id' => $userId]);
        self::assertSame('0', (string)$log->latestForUser($userId, 'marketing')['granted'], 'retirar grava linha nova e vale a última');
        self::assertNull($log->latestForUser($userId, 'terms'));
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM privacy_consents')->fetchColumn(), 'nada é apagado');

        $this->expectException(\InvalidArgumentException::class);
        $log->record('outra', true, '1', 'site');
    }

    public function testSettingsHaveCompanyAndPrivacyOfficerColumns(): void
    {
        $cols = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings'
            AND column_name IN ('site_company_name','site_cnpj','privacy_officer_name','privacy_email')")->fetchAll(\PDO::FETCH_COLUMN);
        sort($cols);
        self::assertSame(['privacy_email', 'privacy_officer_name', 'site_cnpj', 'site_company_name'], $cols);
    }
}
