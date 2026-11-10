<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\PatrimonialEntryScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PatrimonialEntries;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas do ADR 0149 contra o banco: lançamentos patrimoniais (CN-006) com as contas que aceitam lançamento
 * (sem as calculadas pelo balanço) e a lista, e o vínculo da aplicação à conta corrente no cadastro de contas
 * bancárias (CN-003), só para aplicação e só com conta corrente do mesmo condomínio.
 */
final class PatrimonialEntryScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $insert = $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)');
        $insert->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $insert->execute(['Beta', 'active']);
        $this->beta = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, (int)$this->pdo->lastInsertId()]);
        $scope = new CondominiumScope($this->pdo);
        $scope->grant($userId, $this->alpha);
        $scope->grant($userId, $this->beta);
        $this->admin = (new User())->findById($userId);
        $this->registry = new RegistryService($this->pdo);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testPatrimonialEntriesScreen(): void
    {
        $chart = fn (string $code, string $nature, string $kind, string $name): int => (int)$this->registry->save($this->admin, 'chart',
            ['condominium_id' => $this->alpha, 'code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name])['id'];
        $chart('7', 'asset', 'sintetica', 'Ativo');
        $chart('7.1', 'asset', 'analitica', 'Disponível');
        $chart('8', 'liability', 'sintetica', 'Passivo');
        $fund = $chart('8.9', 'liability', 'analitica', 'Fundo de obras');
        $chart('9', 'equity', 'sintetica', 'Patrimônio líquido');
        $result = $chart('9.2', 'equity', 'analitica', 'Resultado do exercício');

        $all = (new PatrimonialEntryScreens())->data($this->admin, 'CN-006');
        self::assertTrue($all['patrimonialReady']);
        self::assertFalse($all['canManagePatrimonial'], 'Sem condomínio na barra, só a lista.');
        self::assertSame([], $all['patrimonialAccounts']);

        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        (new PatrimonialEntries($this->pdo))->create($this->admin, ['condominium_id' => $this->alpha, 'entry_date' => date('Y-m-d'), 'description' => 'Apropriação',
            'amount_cents' => '100,00', 'debit_account_id' => $result, 'credit_account_id' => $fund]);
        $data = (new PatrimonialEntryScreens())->data($this->admin, 'CN-006');
        self::assertTrue($data['canManagePatrimonial']);
        $labels = array_column($data['patrimonialAccounts'], 'label');
        self::assertContains('8.9 Fundo de obras (passivo)', $labels);
        self::assertSame([], array_filter($labels, static fn (string $l): bool => preg_match('/^(7\.1|7\.2|8\.1)( |\.)/', $l) === 1), 'Conta calculada pelo balanço não aparece.');
        self::assertSame(['Apropriação'], array_column($data['patrimonialList']['rows'], 'description'));
        self::assertSame(10000, $data['patrimonialList']['totals']['amount_cents']);
    }

    public function testInvestmentLinkInTheBankAccountForm(): void
    {
        $account = fn (int $condo, string $kind, string $number, ?int $parent = null): array => ['condominium_id' => $condo, 'name' => 'Conta ' . $number, 'bank_code' => '341',
            'branch' => '1234', 'account_number' => $number, 'kind' => $kind, 'parent_bank_account_id' => $parent];
        $checking = (int)$this->registry->save($this->admin, 'bank_accounts', $account($this->alpha, 'corrente', '1000-1'))['id'];
        $savings = (int)$this->registry->save($this->admin, 'bank_accounts', $account($this->alpha, 'poupanca', '1000-2'))['id'];
        $other = (int)$this->registry->save($this->admin, 'bank_accounts', $account($this->beta, 'corrente', '1000-3'))['id'];

        $investment = $this->registry->save($this->admin, 'bank_accounts', $account($this->alpha, 'aplicacao', '1000-4', $checking));
        self::assertSame($checking, (int)$this->pdo->query('SELECT parent_bank_account_id FROM erp_bank_accounts WHERE id = ' . (int)$investment['id'])->fetchColumn());

        foreach ([[$this->alpha, 'corrente', '1000-5', $checking, 'Só uma conta de tipo aplicação'], [$this->alpha, 'aplicacao', '1000-6', $savings, 'conta corrente ativa'],
            [$this->alpha, 'aplicacao', '1000-7', $other, 'outro condomínio']] as [$condo, $kind, $number, $parent, $message]) {
            try {
                $this->registry->save($this->admin, 'bank_accounts', $account($condo, $kind, $number, $parent));
                self::fail("{$kind} com vínculo {$parent} deveria ser recusada.");
            } catch (RegistryValidationException $error) {
                self::assertStringContainsString($message, (string)($error->errors['parent_bank_account_id'] ?? ''), $number);
            }
        }
    }
}
