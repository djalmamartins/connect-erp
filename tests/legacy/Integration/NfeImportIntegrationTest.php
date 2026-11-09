<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use MovesOSTests\Unit\NfeParserTest;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Registry\RegistryService;

/** NF-e XML (ADR 0086): prévia, contas a pagar por duplicata, chave única por condomínio e fornecedor cadastrado. */
final class NfeImportIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private Imports $imports;
    private int $expense;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Unit/NfeParserTest.php';
        $this->pdo->prepare('INSERT INTO condominiums (name, document, status) VALUES (?, ?, ?)')->execute(['Alpha', '44555666000199', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->expense = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $this->imports = new Imports($this->pdo, null, sys_get_temp_dir() . '/nfe-test-' . bin2hex(random_bytes(4)), true);
    }

    public function testInstallmentsBecomePayablesOncePerKey(): void
    {
        $key = NfeParserTest::key(str_repeat('1', 43));
        $dups = '<cobr><fat><vLiq>1500.00</vLiq></fat><dup><nDup>001</nDup><dVenc>2026-12-05</dVenc><vDup>750.00</vDup></dup>'
            . '<dup><nDup>002</nDup><dVenc>2027-01-05</dVenc><vDup>750.00</vDup></dup></cobr>';
        $xml = NfeParserTest::xml($key, $dups);

        $missing = $this->imports->preview($this->admin, 'nfe', ['condominium_id' => $this->alpha, 'chart_account_id' => $this->expense], $this->upload($xml));
        self::assertStringContainsString('não está cadastrado', $missing['preview'][0]['errors'][0], 'Fornecedor não é criado sozinho.');

        $this->service->save($this->admin, 'suppliers', ['legal_name' => 'Limpeza & Cia', 'document' => '11222333000181', 'category' => 'limpeza']);
        $preview = $this->imports->preview($this->admin, 'nfe', ['condominium_id' => $this->alpha, 'chart_account_id' => $this->expense], $this->upload($xml));
        self::assertSame([2, 0], [$preview['rows_count'], $preview['error_count']]);
        $applied = $this->imports->apply($this->admin, $preview['id']);
        self::assertSame(2, $applied['summary']['applied']['entries']);
        self::assertSame(['150000'], $this->pdo->query("SELECT SUM(amount_cents) FROM erp_entries WHERE nature = 'despesa' AND import_id = {$preview['id']}")->fetchAll(\PDO::FETCH_COLUMN));

        // Outro arquivo (hash diferente) com a mesma chave: a prévia aponta o erro e a aplicação é recusada.
        $again = $this->imports->preview($this->admin, 'nfe', ['condominium_id' => $this->alpha, 'chart_account_id' => $this->expense], $this->upload($xml . "\n"));
        self::assertStringContainsString('já foi lançada', implode(' ', $again['preview'][0]['errors']));
        $this->expectException(DomainException::class);
        $this->imports->apply($this->admin, $again['id']);
    }

    private function upload(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'nfe');
        file_put_contents($path, $content);
        return ['name' => 'nota.xml', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
