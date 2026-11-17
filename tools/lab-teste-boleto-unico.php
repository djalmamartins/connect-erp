<?php

declare(strict_types=1);

/**
 * Teste do boleto único (ADR 0331) no SANDBOX do banco, só no lab:
 *   docker compose exec -T app php tools/lab-teste-boleto-unico.php --conta=1
 *
 * Num mês livre de 2027 em diante: lança um rateio de R$ 12,34 com fundo de 10% para uma unidade com o tipo da
 * fatura, prepara e emite a fatura de R$ 50,00 (todas as unidades, marcada "TESTE boleto único"), emite o boleto
 * da fatura dessa unidade (deve somar R$ 63,57), simula o pagamento no banco e confere a baixa das três contas.
 * Mesma trava dos dados de exemplo (ADR 0162: ERP_DEMO_DATA, ambiente lab ou dev, banco de laboratório) e só com
 * credencial do ambiente de testes (sandbox) do banco. Nada vai a produção.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\RevenueLaunch;

$opts = getopt('', ['conta:']);
$accountId = (int)($opts['conta'] ?? 1);
$env = getenv('MOVESOS_ENV') ?: (getenv('APP_ENV') ?: '');
$pdo = Connect::getInstance();
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$demoData = defined('ERP_DEMO_DATA') ? constant('ERP_DEMO_DATA') : getenv('ERP_DEMO_DATA');
$refusal = LabSampleData::guard($env, $db, $demoData, getenv('ERP_DEMO_DB') ?: null);
if ($refusal !== null) {
    fwrite(STDERR, $refusal . PHP_EOL);
    exit(1);
}
$acc = $pdo->query('SELECT * FROM erp_bank_accounts WHERE id = ' . $accountId)->fetch(PDO::FETCH_ASSOC);
if (!is_array($acc)) {
    fwrite(STDERR, "Conta bancária {$accountId} não existe.\n");
    exit(1);
}
$cred = $pdo->query('SELECT * FROM erp_bank_credentials WHERE bank_account_id = ' . $accountId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$credText = is_array($cred) ? json_encode(array_intersect_key($cred, array_flip(['environment', 'adapter']))) : 'sem credencial';
echo "Ambiente {$env}, banco {$db}, conta {$accountId} ({$acc['name']}, banco {$acc['bank_code']}), credencial: {$credText}\n";
if (!is_array($cred) || $cred['environment'] !== 'sandbox') {
    fwrite(STDERR, "Recusado: a credencial não é do sandbox.\n");
    exit(1);
}
$condo = (int)$acc['condominium_id'];
$userId = (int)$pdo->query('SELECT id FROM users WHERE level >= 5 ORDER BY level DESC, id LIMIT 1')->fetchColumn();
$user = (new User())->findById($userId);

$unit = (int)$pdo->query("SELECT id FROM erp_units WHERE condominium_id = {$condo} AND status = 'active' AND deleted_at IS NULL AND block_bill_merge = 0 ORDER BY id LIMIT 1")->fetchColumn();
$type = $pdo->query("SELECT id, name FROM erp_invoice_types WHERE status = 'active' AND deleted_at IS NULL AND recurrence = 'mensal' AND (condominium_id IS NULL OR condominium_id = {$condo}) ORDER BY condominium_id IS NULL, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$chart = (int)$pdo->query("SELECT id FROM erp_chart_accounts WHERE nature = 'receita' AND kind = 'analitica' AND status = 'active' AND deleted_at IS NULL AND (condominium_id IS NULL OR condominium_id = {$condo}) ORDER BY code LIMIT 1")->fetchColumn();
if ($unit < 1 || !is_array($type) || $chart < 1 || $userId < 1) {
    fwrite(STDERR, "Faltam dados: unidade {$unit}, tipo " . json_encode($type) . ", conta {$chart}, usuário {$userId}.\n");
    exit(1);
}
$competence = null;
for ($i = 0; $i < 36; $i++) {
    $m = (new DateTimeImmutable('2027-01-01'))->modify("+{$i} month")->format('Y-m');
    $busy = $pdo->prepare("SELECT 1 FROM erp_invoices WHERE condominium_id = ? AND invoice_type_id = ? AND competence = ? AND status <> 'cancelled'");
    $busy->execute([$condo, $type['id'], $m]);
    $closed = $pdo->prepare("SELECT 1 FROM erp_month_closings WHERE condominium_id = ? AND competence = ? AND status = 'closed'");
    $closed->execute([$condo, $m]);
    if (!$busy->fetchColumn() && !$closed->fetchColumn()) {
        $competence = $m;
        break;
    }
}
echo "Usuário {$userId}, unidade {$unit}, tipo da fatura {$type['id']} ({$type['name']}), competência {$competence}\n";
$due = date('Y-m-d', strtotime('+10 days'));

$rateio = (new RevenueLaunch($pdo))->launch($user, ['condominium_id' => $condo, 'apportion' => 'chosen', 'method' => 'fixed', 'unit_ids' => [$unit],
    'amount_cents' => '12,34', 'description' => 'TESTE boleto único (sandbox)', 'chart_account_id' => $chart, 'competence' => $competence,
    'due_on' => $due, 'reserve_percent' => '10', 'reserve_account_id' => $chart, 'invoice_type_id' => (int)$type['id']]);
echo "Rateio: contas " . implode(',', $rateio['entries']) . "\n";

$invoices = new Invoices($pdo);
$draft = $invoices->prepare($user, ['condominium_id' => $condo, 'invoice_type_id' => (int)$type['id'], 'competence' => $competence, 'due_on' => $due,
    'amount_cents' => '50,00', 'notes' => 'TESTE boleto único (sandbox)']);
$issued = $invoices->issue($user, (int)$draft['id']);
$entry = 0;
foreach ($issued['items'] as $item) {
    if ((int)$item['unit_id'] === $unit) {
        $entry = (int)$item['entry_id'];
    }
}
echo "Fatura {$draft['id']} emitida; conta da unidade {$entry}\n";

$charges = new BankCharges($pdo);
$charge = $charges->issue($user, $entry, $accountId);
for ($i = 0; $i < 6 && $charge['status'] === 'pending'; $i++) {
    sleep(5);
    $charge = $charges->refresh($user, (int)$charge['id']);
}
$detail = $charges->find($user, (int)$charge['id']);
echo 'Boleto ' . $charge['id'] . ': ' . $charge['status'] . ', valor ' . $charge['amount_cents'] . ' (esperado 6357), vencimento ' . $charge['due_on']
    . ', junto: ' . json_encode($detail['bundled'], JSON_UNESCAPED_UNICODE) . ', erro: ' . ($charge['last_error'] ?? '') . "\n";
if ($charge['status'] !== 'registered') {
    fwrite(STDERR, "Boleto não registrado; parei antes da simulação.\n");
    exit(2);
}
$paid = $charges->simulatePayment($user, (int)$charge['id']);
for ($i = 0; $i < 6 && $paid['status'] !== 'paid'; $i++) {
    sleep(5);
    $paid = $charges->refresh($user, (int)$charge['id']);
}
$ids = array_merge([$entry], array_column($detail['bundled'], 'entry_id'));
$rows = $pdo->query('SELECT id, amount_cents, paid_on FROM erp_entries WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo 'Depois da simulação: boleto ' . $paid['status'] . ', pago ' . ($paid['paid_cents'] ?? '-') . ', contas ' . json_encode($rows) . "\n";
$ok = $paid['status'] === 'paid' && count(array_filter($rows, static fn (array $r): bool => $r['paid_on'] !== null)) === count($ids) && (int)$charge['amount_cents'] === 6357;
echo $ok ? "RESULTADO: OK\n" : "RESULTADO: CONFERIR\n";
exit($ok ? 0 : 3);
