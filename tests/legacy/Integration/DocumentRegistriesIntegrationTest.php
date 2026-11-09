<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Documentos operacionais: anexo do Disco Virtual do mesmo condomínio, cheque único, vínculos e datas (ADR 0050). */
final class DocumentRegistriesIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
    }

    public function testCheckCopyNeedsImageOfSameCondominiumAndIsUnique(): void
    {
        $image = $this->file($this->alpha);
        $other = $this->file($this->beta);
        $check = ['condominium_id' => $this->alpha, 'bank_account_id' => $this->bank, 'check_number' => '1001', 'issued_on' => '2026-11-09',
            'payee' => 'Fornecedor X', 'amount_cents' => '350,00'];
        $this->assertInvalid('check_copies', $check, 'document_id');                              // sem imagem
        $this->assertInvalid('check_copies', $check + ['document_id' => $other], 'document_id');  // imagem de outro condomínio
        $this->assertInvalid('check_copies', $check + ['document_id' => 999999], 'document_id');  // inexistente
        self::assertSame($image, $this->service->save($this->admin, 'check_copies', $check + ['document_id' => $image])['document_id']);
        try {
            $this->service->save($this->admin, 'check_copies', $check + ['document_id' => $image]);
            self::fail('Registrou o mesmo cheque duas vezes.');
        } catch (RegistryValidationException) {
            self::addToAssertionCount(1);
        }
        $this->pdo->exec('UPDATE erp_documents SET deleted_at = NOW() WHERE id = ' . $image);
        $this->assertInvalid('check_copies', ['check_number' => '1002', 'document_id' => $image] + $check, 'document_id');  // arquivo excluído
    }

    public function testCommitmentEntryLinkAndPouchDates(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $account = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Manutenção'])['id'];
        $entry = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $account,
            'amount_cents' => '900,00', 'competence' => '2026-11', 'description' => 'Bomba', 'due_on' => '2026-11-20'])['id'];
        $commitment = $this->service->save($this->admin, 'commitments', ['condominium_id' => $this->alpha, 'description' => 'Troca da bomba',
            'amount_cents' => '900,00', 'committed_on' => '2026-11-05', 'settled_on' => '2026-11-20', 'entry_id' => $entry, 'status' => 'settled']);
        self::assertSame('settled', $commitment['status']);
        try {
            $this->service->delete($this->admin, 'entries', $entry);
            self::fail('Excluiu lançamento ligado a empenho.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        $this->assertInvalid('commitments', ['condominium_id' => $this->alpha, 'description' => 'X', 'amount_cents' => '1,00', 'committed_on' => '2026-11-05',
            'settled_on' => '2026-11-01'], 'settled_on');

        $pouch = ['condominium_id' => $this->alpha, 'sent_on' => '2026-11-09', 'courier' => 'Motoboy', 'documents_count' => '3', 'contents' => "Notas\nBoletos\nAta"];
        $this->assertInvalid('pouches', $pouch + ['checked_on' => '2026-11-10'], 'received_on');
        self::assertSame('checked', $this->service->save($this->admin, 'pouches', $pouch + ['received_on' => '2026-11-10', 'checked_on' => '2026-11-10',
            'status' => 'checked', 'document_id' => $this->file($this->alpha)])['status']);
        self::assertSame('delivered', $this->service->save($this->admin, 'delivery_notices', ['condominium_id' => $this->alpha, 'recipient' => 'Morador 101',
            'tracking_code' => 'AR123BR', 'sent_on' => '2026-11-01', 'delivered_on' => '2026-11-05', 'status' => 'delivered'])['status']);
    }

    private function file(int $condominium): int
    {
        $this->pdo->prepare("INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256)
            VALUES (?, 'outros', 'Arquivo', 'a.pdf', ?, 'application/pdf', 10, ?)")
            ->execute([$condominium, $condominium . '/2026/' . bin2hex(random_bytes(16)) . '.pdf', hash('sha256', random_bytes(8))]);
        return (int)$this->pdo->lastInsertId();
    }

    private function assertInvalid(string $slug, array $input, string $field): void
    {
        try {
            $this->service->save($this->admin, $slug, $input);
            self::fail("{$slug} aceitou entrada inválida.");
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors);
        }
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
