<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\TariffReadjustment;

/** ADR 0259: faixas novas criadas pelo cadastro com o preço reajustado e a nova vigência; as antigas ficam, com auditoria e nenhum aviso. */
final class TariffReadjustmentTest extends TestCase
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
        foreach ([['Faixa de teste 1', '0', '10', '5,00'], ['Faixa de teste 2', '10', '', '8,00']] as [$name, $from, $to, $price]) {
            $this->registry->save($this->admin, 'tariffs', ['condominium_id' => $this->condominium, 'kind' => 'agua', 'name' => $name, 'from_amount' => $from,
                'to_amount' => $to, 'price_cents' => $price, 'valid_from' => '2026-01-01', 'notes' => 'Observação de teste']);
        }
        $this->pdo->exec("INSERT INTO erp_tariffs (condominium_id, kind, name, from_amount, to_amount, price_cents, valid_from) VALUES (NULL, 'gas', 'Padrão', 0, NULL, 900, '2026-01-01')");
    }

    public function testCreatesTheNewPeriodWithTheReadjustedPrices(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $input = ['condominium_id' => $this->condominium, 'percent' => '10', 'valid_from' => $tomorrow, 'reason' => 'Reajuste da concessionária'];
        $creates = $this->audits('create');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();

        $preview = (new TariffReadjustment($this->pdo))->readjust($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 2, ['gas', 'energia'], [], [550, 880]], [$preview['dry_run'], $preview['changed'], $preview['count'], $preview['skipped_default'],
            $preview['skipped_already'], array_column($preview['kinds'][0]['bands'], 'price_after_cents')]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_tariffs WHERE kind = 'agua'")->fetchColumn(), 'dry_run não grava.');

        $done = (new TariffReadjustment($this->pdo))->readjust($this->admin, $input);
        self::assertSame(2, $done['changed']);
        $rows = $this->pdo->query("SELECT valid_from, from_amount, to_amount, price_cents, name, notes FROM erp_tariffs WHERE kind = 'agua' ORDER BY valid_from, from_amount")
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['2026-01-01', 500], ['2026-01-01', 800], [$tomorrow, 550], [$tomorrow, 880]],
            array_map(static fn (array $r): array => [substr((string)$r['valid_from'], 0, 10), (int)$r['price_cents']], $rows), 'As antigas ficam; as novas valem amanhã.');
        self::assertSame([null, 'Faixa de teste 2', 'Observação de teste'], [$rows[3]['to_amount'], $rows[3]['name'], $rows[3]['notes']], 'Mesmo limite, nome e observação.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_tariffs' AND action = 'readjust'")->fetchColumn());
        self::assertSame($creates + 2, $this->audits('create'), 'A auditoria create do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Faixa de teste', 'Observação de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $again = (new TariffReadjustment($this->pdo))->readjust($this->admin, $input);
        self::assertSame([0, ['agua']], [$again['count'], $again['skipped_already']], 'Já reajustado não entra de novo.');
    }

    public function testChosenKindsAreAllOrNothingAndScope(): void
    {
        $input = ['condominium_id' => $this->condominium, 'percent' => '10', 'valid_from' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'reason' => 'Reajuste da concessionária'];
        try {
            (new TariffReadjustment($this->pdo))->readjust($this->admin, $input + ['kinds' => ['agua', 'gas']]);
            self::fail('O gás pela tarifa padrão entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame('gas', $exception->errors['_kind']);
        }
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_tariffs')->fetchColumn(), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new TariffReadjustment($this->pdo))->readjust($limited, $input);
            self::fail('Reajuste fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(string $action): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_tariffs' AND action = ?");
        $stmt->execute([$action]);
        return (int)$stmt->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
