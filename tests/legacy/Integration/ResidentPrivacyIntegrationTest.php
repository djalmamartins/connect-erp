<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\ScopeTestInput;
use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Privacy\LegalDocuments;
use Source\Services\Privacy\PrivacyCenter;
use Source\Services\Resident\ResidentLinks;

/**
 * Privacidade da conta do morador (ADR 0603, etapa 2): aceite dos termos na versão atual, novidades com
 * consentimento separado e pedidos do titular (LGPD art. 18) que viram chamado LGPD no Help Desk.
 */
final class ResidentPrivacyIntegrationTest extends TestCase
{
    private int $condo;
    private int $unit;
    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES ('Alpha', 'active')")->execute();
        $this->condo = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, '101', 'apartamento')")->execute([$this->condo]);
        $this->unit = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, email, user_id) VALUES (?, ?, '101', 'owner', 'Ana Moradora', 'ana@morador.test', ?)")
            ->execute([$this->condo, $this->unit, $userId]);
        $this->resident = (new User())->findById($userId);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        http_response_code(200);
        parent::tearDown();
    }

    public function testNewAccountMustAcceptAndAcceptanceIsRecordedPerVersion(): void
    {
        $center = new PrivacyCenter($this->pdo);
        $id = (int)$this->resident->id;
        self::assertFalse($center->hasAccepted($id));

        $status = $center->accept($id, 'app');
        self::assertTrue($status['accepted']);
        self::assertFalse($status['marketing'], 'aceitar os termos não liga novidades');
        self::assertSame([LegalDocuments::TERMS, LegalDocuments::PRIVACY], [$status['terms_version'], $status['privacy_version']]);
        $rows = $this->pdo->query('SELECT purpose, granted, version, channel FROM privacy_consents ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['terms', '1', LegalDocuments::TERMS, 'app'], ['privacy', '1', LegalDocuments::PRIVACY, 'app']],
            array_map(static fn (array $r): array => [$r['purpose'], (string)$r['granted'], $r['version'], $r['channel']], $rows));

        // Versão nova dos termos: o aceite antigo não vale mais.
        $this->pdo->exec("UPDATE privacy_consents SET version = '2000-01-01' WHERE purpose = 'terms'");
        self::assertFalse($center->hasAccepted($id));
    }

    public function testMarketingIsSeparateAndCanBeWithdrawn(): void
    {
        $center = new PrivacyCenter($this->pdo);
        $id = (int)$this->resident->id;
        self::assertTrue($center->accept($id, 'convite', true)['marketing']);
        self::assertFalse($center->setMarketing($id, false, 'app')['marketing']);
        self::assertTrue($center->hasAccepted($id), 'retirar novidades não desfaz o aceite dos termos');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM privacy_consents WHERE purpose = 'marketing'")->fetchColumn(), 'histórico guardado');
    }

    public function testHolderRequestOpensLgpdTicketOnTheOwnUnit(): void
    {
        $link = (new ResidentLinks($this->pdo))->forUser($this->resident)[0];
        $result = (new PrivacyCenter($this->pdo))->request($this->resident, $link, 'exclusao', '<b>Apagar</b> meu telefone antigo');
        $ticket = $this->pdo->query('SELECT subject, message, category, priority, origin, condominium_id, unit_id, requester_id FROM studio_support_tickets WHERE id = ' . $result['id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['LGPD', 'high', 'residents'], [$ticket['category'], $ticket['priority'], $ticket['origin']]);
        self::assertSame([$this->condo, $this->unit, (int)$this->resident->id], [(int)$ticket['condominium_id'], (int)$ticket['unit_id'], (int)$ticket['requester_id']]);
        self::assertStringContainsString('apagar', $ticket['subject']);
        self::assertStringContainsString('Apagar meu telefone antigo', $ticket['message'], 'sem HTML');
        self::assertStringNotContainsString('<b>', $ticket['message']);
        self::assertSame(date('Y-m-d', strtotime('+15 days')), $result['answer_until']);

        $this->expectException(\InvalidArgumentException::class);
        (new PrivacyCenter($this->pdo))->request($this->resident, $link, 'inventado');
    }

    public function testApiEndpointsRequireTheCheckboxAndRecordFromTheMobileApp(): void
    {
        self::assertSame(200, $this->call('privacy', 'GET'));
        self::assertSame(422, $this->call('privacyAccept', 'POST', '{"marketing":true}'), 'sem accept_terms não aceita');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM privacy_consents')->fetchColumn());
        self::assertSame(200, $this->call('privacyAccept', 'POST', '{"accept_terms":true,"marketing":false}'));
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM privacy_consents WHERE channel = 'celular' AND user_id = " . (int)$this->resident->id)->fetchColumn());
        self::assertSame(422, $this->call('privacyMarketing', 'POST', '{"marketing":"sim"}'));
        self::assertSame(200, $this->call('privacyMarketing', 'POST', '{"marketing":true}'));
        self::assertSame(422, $this->call('privacyRequest', 'POST', '{"type":"nada"}'));
        self::assertSame(201, $this->call('privacyRequest', 'POST', '{"type":"acesso"}'));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_tickets WHERE category = 'LGPD'")->fetchColumn());
    }

    public function testAppScreensSendToTermsUntilAccepted(): void
    {
        $this->pdo->prepare('UPDATE settings SET access_app = 1')->execute();
        self::assertSame('redirect', $this->screen('home'), 'sem aceite, a tela leva a /termos');
        self::assertSame('page', $this->screen('terms'), 'a própria tela dos termos abre');
        (new PrivacyCenter($this->pdo))->accept((int)$this->resident->id, 'app');
        $_SESSION = [];
        self::assertSame('page', $this->screen('privacy'));
    }

    private function screen(string $action): string
    {
        Auth::actingAs($this->resident);
        \Source\Support\Access::clear();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['pular' => '1'];
        ob_start();
        try {
            (new \Source\Controllers\App\Resident\AppScreens())->$action([]);
            return 'page';
        } catch (\Source\Support\RedirectIntercepted) {
            return 'redirect';
        } finally {
            ob_end_clean();
        }
    }

    private function call(string $action, string $method, string $body = ''): int
    {
        Auth::actingAs($this->resident);
        \Source\Support\Access::clear();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = [];
        $_POST = [];
        if ($method !== 'GET') {
            ScopeTestInput::$body = $body;
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', ScopeTestInput::class);
        }
        ob_start();
        try {
            (new \Source\Controllers\Api\V1\ResidentApp())->$action([]);
            $status = (int)http_response_code();
        } finally {
            ob_end_clean();
            if ($method !== 'GET') {
                stream_wrapper_restore('php');
            }
        }
        return $status;
    }
}
