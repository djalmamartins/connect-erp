<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Proposals\ProposalComposer;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;
use Source\Support\Access;

/** Criar, gerar e enviar propostas no ERP (ADR 0650): validação, LGPD, PDF no modelo da Connect, fila de e-mail e auditoria. */
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

    public function testGenerateThenSendTheConnectModelPdf(): void
    {
        $seller = $this->seller();
        $composer = new ProposalComposer();
        $proposal = $composer->create($this->input(), $seller);
        $id = (int)$proposal['id'];
        $form = ProposalComposer::defaults($proposal);

        try {
            $composer->respond($id, ['valid_until' => '2020-01-01', 'subject' => ''] + $form, $seller, false);
            self::fail('validade no passado');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('valid_until', $error->fields);
            self::assertArrayHasKey('subject', $error->fields);
        }

        $generated = $composer->respond($id, $form, $seller, false);
        self::assertSame(['generated', false], [$generated['status'], $generated['queued']]);
        $file = $composer->pdfFile($id, $generated['id']);
        self::assertNotNull($file);
        $this->files[] = (string)$file;
        self::assertStringStartsWith('%PDF', (string)file_get_contents((string)$file, false, null, 0, 4));
        self::assertNull($composer->pdfFile($id + 999, $generated['id']), 'PDF só pela proposta dona da versão');
        self::assertSame('contacted', (new ProposalDesk())->find($id)['status'], 'só gerar não muda a situação');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());

        $sent = $composer->respond($id, $form, $seller, true);
        $this->files[] = (string)$composer->pdfFile($id, $sent['id']);
        self::assertSame(['queued', true], [$sent['status'], $sent['queued']]);
        $mail = $this->pdo->query('SELECT recipient_email, subject, attachments_json FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('sindico@exemplo.test', $mail['recipient_email']);
        self::assertSame($form['subject'], $mail['subject']);
        self::assertStringContainsString('.pdf', (string)$mail['attachments_json']);
        self::assertSame('proposal_sent', (new ProposalDesk())->find($id)['status']);
        $versions = (new ProposalDesk())->responses($id);
        self::assertSame([$sent['id'], $generated['id']], array_column($versions, 'id'));
        self::assertTrue($versions[0]['has_pdf']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'proposal_responses' AND action = 'send'")->fetchColumn());

        $this->pdo->exec("UPDATE proposals SET status = 'won' WHERE id = {$id}");
        $again = $composer->respond($id, $form, $seller, true);
        $this->files[] = (string)$composer->pdfFile($id, $again['id']);
        self::assertSame('won', (new ProposalDesk())->find($id)['status'], 'enviar de novo não desfaz Fechada');

        try {
            $composer->respond(999999, $form, $seller, false);
            self::fail('proposta inexistente');
        } catch (ProposalDeskError $error) {
            self::assertSame(404, $error->status);
        }
    }
}
