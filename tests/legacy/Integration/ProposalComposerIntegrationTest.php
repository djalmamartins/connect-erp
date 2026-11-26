<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Proposals\ProposalComposer;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;
use Source\Support\Access;

/** Criar, anexar e enviar propostas no ERP (ADR 0653): validação, LGPD, até dois PDFs, antivírus, fila de e-mail e auditoria. */
final class ProposalComposerIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function seller(): User
    {
        $id = $this->createUser(['first_name' => 'Comercial']);
        $this->pdo->prepare("INSERT INTO access_roles (name, slug, level) VALUES ('Comercial', 'comercial_teste', 50)")->execute();
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('proposals.manage', 'proposals.manage', 'Teste')")->execute();
        $permission = (int)$this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'proposals.manage'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        Access::clear();
        return (new User())->findById($id);
    }

    /** @return array<string,mixed> */
    private function input(array $over = []): array
    {
        return $over + ['name' => 'Síndico Fictício', 'email' => 'SINDICO@exemplo.test', 'whatsapp' => '(31) 97777-0000', 'condominium' => 'Residencial Teste - Bloco 08',
            'units' => '32', 'profile' => 'sindico', 'message' => 'Ligou pedindo proposta.', 'origin' => 'telefone', 'requested' => '1'];
    }

    public function testCreateValidatesAndRecordsWithoutEmailingThePerson(): void
    {
        $seller = $this->seller();
        $composer = new ProposalComposer();
        try {
            $composer->create($this->input(['requested' => null, 'origin' => 'site', 'email' => 'x']), $seller);
            self::fail('sem o pedido da pessoa não grava');
        } catch (ProposalDeskError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('requested', $error->fields);
            self::assertArrayHasKey('origin', $error->fields, '"site" é só do formulário público');
            self::assertArrayHasKey('email', $error->fields);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn());

        $proposal = $composer->create($this->input(), $seller);
        self::assertMatchesRegularExpression('/^PROP-\d{6}-[0-9A-F]{6}$/', $proposal['protocol']);
        self::assertSame('sindico@exemplo.test', $proposal['email']);
        self::assertSame(['contacted', 'telefone', 'Telefone', (int)$seller->id, (int)$seller->id, false],
            [$proposal['status'], $proposal['origin'], $proposal['origin_label'], $proposal['assigned_to'], $proposal['created_by'], $proposal['marketing_consent']]);
        self::assertNotNull($proposal['contacted_at']);
        self::assertSame(0, (new ProposalDesk())->newCount(), 'cadastrada no ERP não acende o alerta de nova');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'cadastrar não manda e-mail');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'proposals' AND action = 'create'")->fetchColumn());
    }

    /** Arquivo temporário como o do upload (fora de storage). */
    private function upload(string $content = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"): array
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'prop');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return ['tmp_name' => $path, 'name' => 'proposta.pdf', 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    public function testAttachUpToTwoPdfsAndSendThemInOneEmail(): void
    {
        $seller = $this->seller();
        $composer = new ProposalComposer();
        $proposal = $composer->create($this->input(), $seller);
        $id = (int)$proposal['id'];
        $form = ProposalComposer::defaults($proposal);
        $off = new \Source\Services\Erp\Antivirus\UploadScanPolicy('off');

        try {
            $composer->attach($id, $form, [], $seller, false, $off);
            self::fail('sem PDF');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('pdf', $error->fields);
        }
        try {
            $composer->attach($id, ['valid_until' => '2020-01-01', 'subject' => ''] + $form, ['syndic' => $this->upload('não sou pdf')], $seller, false, $off);
            self::fail('arquivo que não é PDF, validade no passado e sem assunto');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('pdf_syndic', $error->fields);
            self::assertArrayHasKey('valid_until', $error->fields);
            self::assertArrayHasKey('subject', $error->fields);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM proposal_responses')->fetchColumn());

        $saved = $composer->attach($id, $form, ['administrator' => $this->upload()], $seller, false, $off);
        self::assertSame(['generated', false], [$saved['status'], $saved['queued']]);
        $file = $composer->pdfFile($id, $saved['ids'][0]);
        self::assertNotNull($file);
        $this->files[] = (string)$file;
        self::assertStringStartsWith('%PDF', (string)file_get_contents((string)$file, false, null, 0, 4));
        self::assertStringContainsString('-administradora-v', (string)$file);
        self::assertNull($composer->pdfFile($id + 999, $saved['ids'][0]), 'PDF só pela proposta dona da versão');
        self::assertSame('contacted', (new ProposalDesk())->find($id)['status'], 'só anexar não muda a situação');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());

        $sent = $composer->attach($id, ['introduction' => 'Conforme conversamos.'] + $form, ['administrator' => $this->upload(), 'syndic' => $this->upload()], $seller, true, $off);
        foreach ($sent['ids'] as $responseId) {
            $this->files[] = (string)$composer->pdfFile($id, $responseId);
        }
        self::assertCount(2, $sent['ids']);
        self::assertSame(['queued', true], [$sent['status'], $sent['queued']]);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'um e-mail só');
        $mail = $this->pdo->query('SELECT recipient_email, subject, body, attachments_json FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('sindico@exemplo.test', $mail['recipient_email']);
        self::assertSame($form['subject'], $mail['subject']);
        self::assertStringContainsString('Conforme conversamos.', (string)$mail['body']);
        self::assertStringContainsString('proposta-administradora-', (string)$mail['attachments_json']);
        self::assertStringContainsString('proposta-sindico-profissional-', (string)$mail['attachments_json']);
        self::assertSame('proposal_sent', (new ProposalDesk())->find($id)['status']);
        $versions = (new ProposalDesk())->responses($id);
        self::assertCount(3, $versions);
        self::assertSame(['syndic', 'administrator', 'administrator'], array_column($versions, 'template_type'));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'proposal_responses' AND action = 'send'")->fetchColumn());

        $this->pdo->exec("UPDATE proposals SET status = 'won' WHERE id = {$id}");
        $again = $composer->resend($id, [$saved['ids'][0]], $seller);
        self::assertTrue($again['queued']);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());
        self::assertSame('won', (new ProposalDesk())->find($id)['status'], 'enviar de novo não desfaz Fechada');
        try {
            $composer->resend($id, [999999], $seller);
            self::fail('versão de outra proposta');
        } catch (ProposalDeskError $error) {
            self::assertSame(422, $error->status);
        }

        try {
            $composer->attach(999999, $form, ['syndic' => $this->upload()], $seller, false, $off);
            self::fail('proposta inexistente');
        } catch (ProposalDeskError $error) {
            self::assertSame(404, $error->status);
        }
    }

    public function testInfectedFileIsRefused(): void
    {
        $seller = $this->seller();
        $composer = new ProposalComposer();
        $proposal = $composer->create($this->input(), $seller);
        $scanner = new class implements \Source\Services\Erp\Antivirus\UploadScanner {
            public function scan(string $path): string { return \Source\Services\Erp\Antivirus\ScanStatus::INFECTED; }
            public function available(): bool { return true; }
        };
        try {
            $composer->attach((int)$proposal['id'], ProposalComposer::defaults($proposal), ['syndic' => $this->upload()], $seller, true,
                new \Source\Services\Erp\Antivirus\UploadScanPolicy('enforce', $scanner));
            self::fail('arquivo infectado');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('pdf_syndic', $error->fields);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM proposal_responses')->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());
    }

    public function testQuoteIsSavedAndAudited(): void
    {
        $seller = $this->seller();
        $composer = new ProposalComposer();
        $proposal = $composer->create($this->input(['units' => '40']), $seller);
        $id = (int)$proposal['id'];
        try {
            $composer->saveQuote($id, ['price_admin_unit' => 'abc']);
            self::fail('valor inválido');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('price_admin_unit', $error->fields);
        }
        $saved = $composer->saveQuote($id, ['price_admin_unit' => '12,50', 'price_syndic_unit' => '', 'min_monthly' => '1.000,00']);
        self::assertSame([1250, null, 100000, 50000, 600000, false], [$saved['quote']['admin_unit'], $saved['quote']['syndic_unit'], $saved['quote']['min_monthly'],
            $saved['quote']['monthly'], $saved['quote']['yearly'], $saved['quote']['worth']]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'proposals' AND action = 'quote'")->fetchColumn());
    }

    public function testMigrationKeepsTheProposalPermissionOnlyForAdministrationAndDevelopment(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('proposals.manage', 'proposals.manage', 'Teste')");
        $permission = (int)$this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'proposals.manage'")->fetchColumn();
        foreach (['developer', 'super_admin', 'client_admin', 'manager', 'operator'] as $slug) {
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 10)')->execute([$slug, $slug]);
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([(int)$this->pdo->lastInsertId(), $permission]);
        }
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261126_0900_proposals_origin.sql');
        $sql = (string)preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->pdo->exec($statement);
        }
        $roles = $this->pdo->query("SELECT r.slug FROM access_role_permissions rp JOIN access_roles r ON r.id = rp.role_id WHERE rp.permission_id = {$permission} ORDER BY r.slug")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['client_admin', 'developer', 'super_admin'], $roles);
    }
}
