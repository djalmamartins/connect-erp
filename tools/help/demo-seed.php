<?php

declare(strict_types=1);

/*
 * Base de demonstração dos prints da Central de Ajuda (ADR 0141).
 *
 * Os prints vão para um site público, então NUNCA são tirados do banco do lab ou de produção:
 * este script monta um banco próprio, terminado em _test (a mesma trava da suíte de testes), com
 * o esquema completo e só dados fictícios (três condomínios inventados, nomes e documentos
 * gerados). Uso (o tools/help/prints.sh já faz isso):
 *
 *   MOVESOS_ENV=testing MOVESOS_TEST_DB=connect_ajuda_test MOVESOS_TEST_DB_HOST=... \
 *   MOVESOS_TEST_DB_USER=... MOVESOS_TEST_DB_PASS=... php tools/help/demo-seed.php
 *
 * Saída: e-mail e senha do usuário de demonstração (papel developer, sem 2FA obrigatório).
 */

$database = getenv('MOVESOS_TEST_DB') ?: '';
if ((getenv('MOVESOS_ENV') ?: '') !== 'testing' || !preg_match('/_test$/', $database)) {
    fwrite(STDERR, "Recusado: rode com MOVESOS_ENV=testing e um banco terminado em _test (os prints nunca usam dados reais).\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/tests/legacy/bootstrap.php';

use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Support\TicketService;

/** @var PDO $pdo */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if (!in_array($table, ['settings', 'access_permissions', 'migrations', 'schema_migrations'], true)) {
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("UPDATE settings SET site_name = 'Connect Condomínios', site_title = 'Connect Condomínios', access_studio = 1, access_erp = 1, access_app = 1, access_site = 1, access_support = 1 WHERE id = 1");

// Permissões cadastradas pelas migrations (como no banco real), papel developer com tudo.
foreach (array_merge(glob($root . '/database/legacy-migrations/migrations/*.sql') ?: [], glob($root . '/database/migrations/*.sql') ?: []) as $file) {
    if (str_ends_with($file, '.down.sql') || !preg_match_all('/INSERT[^;]*?INTO\s+`?access_permissions`?[^;]*?VALUES(.*?);/is', (string)file_get_contents($file), $blocks)) {
        continue;
    }
    foreach ($blocks[1] as $values) {
        preg_match_all("/\\(\\s*'([^']*)'\\s*,\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", $values, $tuples, PREG_SET_ORDER);
        foreach ($tuples as [, $name, $slug]) {
            $pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'Sistema')")->execute([$name, $slug]);
        }
    }
}
$pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos os condomínios', '" . CondominiumScope::ALL_PERMISSION . "', 'ERP')");
$pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Desenvolvedor', 'developer', 100)");
$roleId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT {$roleId}, id FROM access_permissions");

$password = getenv('HELP_DEMO_PASSWORD') ?: 'Ajuda@' . date('Y');
$pdo->prepare("INSERT INTO users (first_name, last_name, email, document, password, level, status) VALUES ('Ana', 'Demonstração', 'ajuda@connect.demo', ?, ?, 10, 'confirmed')")
    ->execute([cpf(1), password_hash($password, PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
$admin = (new User())->findById($userId);
\Source\Support\Access::clear();

$today = new DateTimeImmutable('today');
$month = static fn (int $offset): string => $today->modify('first day of this month')->modify("{$offset} month")->format('Y-m');
$day = static fn (string $competence, int $d): string => $competence . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
$date = static fn (int $days): string => $today->modify("{$days} day")->format('Y-m-d');

$registry = new RegistryService($pdo);
$save = static function (string $slug, array $data) use ($registry, $admin): int {
    return (int)$registry->save($admin, $slug, $data)['id'];
};

$condominiums = [
    ['Residencial Jardim das Acácias', 'Condomínio Residencial Jardim das Acácias', 'Rua das Acácias', '480', 'Jardim Botânico', 'Ribeirão Preto', 'SP', '14021650', 2, ['A', 'B'], 4],
    ['Edifício Mirante do Lago', 'Condomínio Edifício Mirante do Lago', 'Avenida do Lago', '1200', 'Lagoa Nova', 'Ribeirão Preto', 'SP', '14025120', 1, [''], 6],
    ['Condomínio Parque das Palmeiras', 'Associação Parque das Palmeiras', 'Alameda das Palmeiras', '90', 'Vila Verde', 'Sertãozinho', 'SP', '14160300', 1, [''], 4],
];
$firstNames = ['Mariana', 'Rafael', 'Beatriz', 'Lucas', 'Fernanda', 'Gustavo', 'Juliana', 'Thiago', 'Camila', 'Bruno', 'Larissa', 'Diego', 'Patrícia', 'Rodrigo', 'Aline', 'Felipe', 'Renata', 'Marcelo', 'Vanessa', 'André'];
$lastNames = ['Albuquerque', 'Monteiro', 'Figueira', 'Sampaio', 'Teixeira', 'Valadares', 'Queiroz', 'Rezende', 'Bittencourt', 'Pacheco'];
$person = static function (int $n) use ($firstNames, $lastNames): string {
    return $firstNames[$n % count($firstNames)] . ' ' . $lastNames[intdiv($n, 2) % count($lastNames)];
};

$scope = new CondominiumScope($pdo);
$ids = [];
$unitsBy = [];
$n = 0;
foreach ($condominiums as $index => [$name, $legal, $street, $number, $district, $city, $state, $zip, $blocks, $blockNames, $floors]) {
    $pdo->prepare("INSERT INTO condominiums (name, legal_name, document, status, zip_code, street, number, district, city, state, phone, email, blocks_count, units_count, contract_start_date, fee_due_day, created_by) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10, ?)")
        ->execute([$name, $legal, cnpj(10 + $index), $zip, $street, $number, $district, $city, $state, '1636001' . (200 + $index), 'sindico' . ($index + 1) . '@exemplo.com.br', $blocks, 0, $date(-400 + $index * 90), $userId]);
    $condo = (int)$pdo->lastInsertId();
    $ids[] = $condo;
    $scope->grant($userId, $condo);
    $unitsBy[$condo] = [];
    foreach ($blockNames as $block) {
        for ($floor = 1; $floor <= $floors; $floor++) {
            foreach ([1, 2] as $position) {
                $unitNumber = $index === 2 ? 'Casa ' . (($floor - 1) * 2 + $position) : $floor . '0' . $position;
                $unitsBy[$condo][] = $save('units', ['condominium_id' => $condo, 'block' => $block, 'number' => $unitNumber, 'type' => $index === 2 ? 'casa' : 'apartamento', 'floor' => $index === 2 ? '' : (string)$floor, 'ideal_fraction' => number_format(1 / ($floors * 2 * count($blockNames)), 6, ',', ''), 'private_area' => $index === 2 ? '180,00' : '72,50']);
            }
        }
    }
    $pdo->prepare('UPDATE condominiums SET units_count = ? WHERE id = ?')->execute([count($unitsBy[$condo]), $condo]);

    foreach ($unitsBy[$condo] as $i => $unit) {
        $n++;
        $save('residents', ['condominium_id' => $condo, 'unit_id' => $unit, 'relationship' => 'owner', 'name' => $person($n), 'document' => cpf(100 + $n), 'email' => 'morador' . $n . '@exemplo.com.br', 'phone' => '(16) 9' . (8100 + $n) . '-' . (2000 + $n), 'starts_on' => $date(-700 + $n), 'billing_by_email' => '1']);
        if ($i % 3 === 1) {
            $n++;
            $save('residents', ['condominium_id' => $condo, 'unit_id' => $unit, 'relationship' => 'tenant', 'name' => $person($n), 'document' => cpf(100 + $n), 'email' => 'morador' . $n . '@exemplo.com.br', 'phone' => '(16) 9' . (8100 + $n) . '-' . (2000 + $n), 'starts_on' => $date(-200 + $n)]);
        }
    }
    $save('board', ['condominium_id' => $condo, 'role' => 'sindico', 'name' => $person(30 + $index), 'document' => cpf(300 + $index), 'unit_id' => $unitsBy[$condo][0], 'term_starts_on' => $date(-300), 'term_ends_on' => $date(430), 'email' => 'sindico' . ($index + 1) . '@exemplo.com.br', 'phone' => '(16) 99100-120' . $index]);
    $save('board', ['condominium_id' => $condo, 'role' => 'fiscal', 'name' => $person(40 + $index), 'unit_id' => $unitsBy[$condo][2], 'term_starts_on' => $date(-300), 'term_ends_on' => $date(430), 'email' => 'conselho' . ($index + 1) . '@exemplo.com.br', 'phone' => '(16) 99100-130' . $index]);
    $save('employees', ['condominium_id' => $condo, 'job' => 'zelador', 'name' => $person(50 + $index), 'document' => cpf(500 + $index), 'hired_on' => $date(-900), 'shift' => 'comercial', 'salary_cents' => '2.850,00', 'phone' => '(16) 99200-310' . $index]);
    $save('employees', ['condominium_id' => $condo, 'job' => 'porteiro', 'name' => $person(60 + $index), 'document' => cpf(600 + $index), 'hired_on' => $date(-500), 'shift' => '12x36', 'salary_cents' => '2.300,00']);
    $save('vehicles', ['condominium_id' => $condo, 'unit_id' => $unitsBy[$condo][1], 'plate' => 'BRA2E1' . $index, 'kind' => 'carro', 'model' => 'Hatch compacto', 'color' => 'Prata', 'parking_spot' => 'G-0' . ($index + 1)]);
    $save('visitors', ['condominium_id' => $condo, 'unit_id' => $unitsBy[$condo][3], 'name' => $person(70 + $index), 'phone' => '(16) 99300-410' . $index, 'permanent_access' => '1']);
    $save('pets', ['condominium_id' => $condo, 'unit_id' => $unitsBy[$condo][4], 'name' => ['Thor', 'Mel', 'Bidu'][$index], 'species' => 'cao', 'size' => 'medio', 'breed' => 'SRD', 'vaccinated' => '1']);
    $party = $save('common_areas', ['condominium_id' => $condo, 'name' => 'Salão de festas', 'booking_mode' => 'approval', 'capacity' => '80', 'booking_fee_cents' => '150,00', 'min_advance_days' => '3', 'max_advance_days' => '90', 'opening_time' => '10:00', 'closing_time' => '23:00', 'rules' => 'Uso até 23h. Limpeza por conta do morador.']);
    $grill = $save('common_areas', ['condominium_id' => $condo, 'name' => 'Churrasqueira', 'booking_mode' => 'free', 'capacity' => '30', 'opening_time' => '10:00', 'closing_time' => '22:00']);
    $save('linkable_areas', ['condominium_id' => $condo, 'kind' => 'vaga', 'identifier' => 'G-0' . ($index + 1), 'unit_id' => $unitsBy[$condo][1], 'location' => 'Subsolo']);
    $save('assets', ['condominium_id' => $condo, 'description' => 'Cortador de grama', 'asset_number' => 'PAT-00' . ($index + 1), 'category' => 'equipamento', 'condition' => 'bom', 'acquired_on' => $date(-365), 'value_cents' => '1.890,00', 'location' => 'Depósito']);
    $save('reservations', ['condominium_id' => $condo, 'common_area_id' => $party, 'unit_id' => $unitsBy[$condo][2], 'resident_name' => $person(80 + $index), 'reserved_on' => $date(12 + $index), 'starts_time' => '18:00', 'ends_time' => '23:00', 'fee_cents' => '150,00']);
    $save('reservations', ['condominium_id' => $condo, 'common_area_id' => $grill, 'unit_id' => $unitsBy[$condo][5], 'resident_name' => $person(90 + $index), 'reserved_on' => $date(5 + $index), 'starts_time' => '12:00', 'ends_time' => '17:00']);
    $save('occurrences', ['condominium_id' => $condo, 'unit_id' => $unitsBy[$condo][3], 'subject' => 'Barulho após as 22h', 'priority' => 'media', 'occurred_on' => $date(-3), 'description' => 'Morador relatou som alto no apartamento vizinho depois das 22h, em dia de semana.']);
    $save('occurrences', ['condominium_id' => $condo, 'subject' => 'Lâmpada queimada na garagem', 'priority' => 'baixa', 'occurred_on' => $date(-1), 'description' => 'Duas lâmpadas do corredor da garagem estão queimadas.']);
    $save('moves', ['condominium_id' => $condo, 'unit_id' => $unitsBy[$condo][6], 'resident_name' => $person(95 + $index), 'kind' => 'entrada', 'scheduled_on' => $date(8), 'period' => 'manha']);
}

$suppliers = [];
foreach ([['Elevadores Ascensão Ltda', 'elevadores'], ['Limpa Bem Serviços', 'limpeza'], ['Verde Vivo Jardinagem', 'jardinagem'], ['Águas da Cidade', 'concessionaria']] as $i => [$supplierName, $category]) {
    $suppliers[] = $save('suppliers', ['legal_name' => $supplierName, 'document' => cnpj(40 + $i), 'trade_name' => explode(' ', $supplierName)[0], 'category' => $category, 'email' => 'contato' . $i . '@fornecedor.exemplo', 'phone' => '(16) 3300-450' . $i]);
}
$save('lawyers', ['name' => 'Helena Duarte Ramos', 'oab' => 'SP123456', 'document' => cpf(900), 'firm' => 'Duarte Ramos Advocacia', 'email' => 'helena@advocacia.exemplo', 'fee_percent' => '10']);
$save('contacts', ['service' => 'eletricista', 'name' => 'Eletro Luz Plantão', 'phone' => '(16) 99400-5100']);
$save('contacts', ['service' => 'encanador', 'name' => 'Hidráulica Rápida', 'phone' => '(16) 99400-5200']);
foreach ($ids as $index => $condo) {
    $save('maintenances', ['condominium_id' => $condo, 'service' => 'Manutenção preventiva dos elevadores', 'supplier_id' => $suppliers[0], 'planned_on' => $date(6 + $index), 'frequency' => 'mensal', 'description' => 'Visita mensal obrigatória.']);
    $save('contracts', ['condominium_id' => $condo, 'supplier_id' => $suppliers[1], 'object' => 'Limpeza das áreas comuns', 'starts_on' => $date(-200), 'ends_on' => $date(165), 'monthly_cents' => '4.200,00', 'readjustment' => 'ipca']);
    $save('insurances', ['condominium_id' => $condo, 'insurer' => 'Segura Mais Seguros', 'policy_number' => 'APL-2026-00' . ($index + 1), 'starts_on' => $date(-100), 'ends_on' => $date(265), 'premium_cents' => '6.800,00', 'coverage_cents' => '5.000.000,00']);
}

// Financeiro do primeiro condomínio: plano de contas, contas, fatura, boletos, retorno e acordo.
$alpha = $ids[0];
$save('chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
$fee = $save('chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial']);
$agreementAccount = $save('chart', ['code' => '1.02', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Acordos']);
$save('chart', ['code' => '1.03', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Multas e juros']);
$save('chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
$staff = $save('chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Pessoal']);
$water = $save('chart', ['code' => '2.02', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água e esgoto']);
$upkeep = $save('chart', ['code' => '2.03', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Manutenção']);
$center = $save('cost_centers', ['code' => '01', 'name' => 'Ordinário']);
$save('cost_centers', ['code' => '02', 'name' => 'Fundo de reserva']);
$type = $save('invoice_types', ['condominium_id' => $alpha, 'name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '650,00']);
$bank = $save('bank_accounts', ['condominium_id' => $alpha, 'name' => 'Conta movimento', 'bank_code' => '001', 'branch' => '3456', 'account_number' => '12345-6', 'kind' => 'corrente', 'wallet' => '17', 'opening_balance_cents' => '18.500,00', 'opened_on' => $day($month(-3), 1), 'is_main' => '1', 'cost_center_id' => $center]);
$save('bank_accounts', ['condominium_id' => $alpha, 'name' => 'Fundo de reserva', 'bank_code' => '001', 'branch' => '3456', 'account_number' => '65432-1', 'kind' => 'poupanca', 'opening_balance_cents' => '42.000,00', 'opened_on' => $day($month(-3), 1)]);
foreach ($ids as $other) {
    if ($other !== $alpha) {
        $save('bank_accounts', ['condominium_id' => $other, 'name' => 'Conta movimento', 'bank_code' => '341', 'branch' => '0101', 'account_number' => '98765-4', 'kind' => 'corrente', 'opening_balance_cents' => '9.800,00', 'opened_on' => $day($month(-3), 1), 'is_main' => '1']);
    }
}
foreach ([-2, -1, 0] as $offset) {
    $competence = $month($offset);
    $paid = $offset < 0 ? $day($competence, 12) : null;
    $save('payables', ['condominium_id' => $alpha, 'competence' => $competence, 'description' => 'Folha de pagamento', 'amount_cents' => '5.150,00', 'chart_account_id' => $staff, 'cost_center_id' => $center, 'due_on' => $day($competence, 5), 'paid_on' => $paid, 'bank_account_id' => $paid ? $bank : null]);
    $save('payables', ['condominium_id' => $alpha, 'competence' => $competence, 'description' => 'Conta de água', 'amount_cents' => '1.320,40', 'chart_account_id' => $water, 'supplier_id' => $suppliers[3], 'due_on' => $day($competence, 18), 'paid_on' => $paid, 'bank_account_id' => $paid ? $bank : null]);
    $save('payables', ['condominium_id' => $alpha, 'competence' => $competence, 'description' => 'Manutenção dos elevadores', 'amount_cents' => '890,00', 'chart_account_id' => $upkeep, 'supplier_id' => $suppliers[0], 'due_on' => $day($competence, 20), 'paid_on' => $paid, 'bank_account_id' => $paid ? $bank : null]);
}
$save('recurring_entries', ['condominium_id' => $alpha, 'nature' => 'despesa', 'description' => 'Limpeza das áreas comuns', 'amount_cents' => '4.200,00', 'chart_account_id' => $upkeep, 'supplier_id' => $suppliers[1], 'bank_account_id' => $bank, 'day_of_month' => '10', 'starts_competence' => $month(-2)]);

$invoices = new Invoices($pdo);
$charges = new BankCharges($pdo);
$returns = new BankReturns($pdo);
$late = [];
foreach ([-2, -1, 0] as $offset) {
    $competence = $month($offset);
    $invoice = $invoices->issue($admin, $invoices->prepare($admin, ['condominium_id' => $alpha, 'invoice_type_id' => $type, 'competence' => $competence, 'due_on' => $day($competence, 10)])['id']);
    $events = [];
    foreach ($invoice['items'] as $position => $item) {
        if ($offset < 0 && $position % 5 === 3) {
            // Em atraso e sem boleto: vira o acordo de demonstração.
            $late[$item['unit_id'] ?? 0][] = (int)$item['entry_id'];
            continue;
        }
        $charge = $charges->issue($admin, (int)$item['entry_id'], $bank);
        if ($offset < 0 && $position % 5 !== 4) {
            $events[] = ['event_key' => 'DEMO-' . $competence . '-' . $position, 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => $charge['amount_cents'], 'occurred_on' => $day($competence, 9)];
        }
    }
    if ($events) {
        $returns->process($admin, $bank, 'retorno-' . $competence . '.ret', SandboxBankAdapter::buildReturn($events));
    }
}
foreach ($late as $unitId => $entries) {
    if ($unitId && count($entries) >= 2) {
        (new Agreements($pdo))->create($admin, ['unit_id' => $unitId, 'entry_ids' => $entries, 'installments' => 3, 'first_due_on' => $date(20), 'chart_account_id' => $agreementAccount]);
        break;
    }
}

$tickets = new TicketService($pdo);
$tickets->open(['subject' => 'Dúvida sobre a segunda via do boleto', 'message' => 'Não recebi o boleto deste mês por e-mail. Como faço para emitir a segunda via?', 'requester_name' => 'Mariana Albuquerque', 'requester_email' => 'morador1@exemplo.com.br', 'condominium_id' => $alpha], 'site', null);
$tickets->open(['subject' => 'Portão da garagem fazendo barulho', 'message' => 'O portão da garagem está rangendo ao abrir. Podem verificar com a manutenção?', 'condominium_id' => $alpha], 'erp', $admin);

// Aplicativo do morador (ADR 0302): conta de morador ligada à 1ª unidade do primeiro condomínio, todas as funções
// ligadas na matriz do portal e alguns comunicados, encomendas, visitas e uma assembleia convocada.
$residentRow = $pdo->query('SELECT id, name, unit_id FROM erp_residents WHERE condominium_id = ' . $alpha . " AND relationship = 'owner' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("INSERT INTO users (first_name, last_name, email, document, password, level, status) VALUES (?, ?, 'morador@connect.demo', ?, ?, 1, 'confirmed')")
    ->execute([explode(' ', (string)$residentRow['name'])[0], explode(' ', (string)$residentRow['name'], 2)[1] ?? '', cpf(2), password_hash($password, PASSWORD_DEFAULT)]);
$residentUser = (int)$pdo->lastInsertId();
$pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level) VALUES ('Usuário', 'user', 1)");
$pdo->prepare("INSERT IGNORE INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'user'")->execute([$residentUser]);
$pdo->prepare('UPDATE erp_residents SET user_id = ? WHERE id = ?')->execute([$residentUser, (int)$residentRow['id']]);
$portal = new \Source\Services\Erp\ResidentPortalPermissions($pdo);
foreach (array_keys(\Source\Services\Erp\ResidentPortalPermissions::FEATURES) as $feature) {
    $portal->set($admin, $alpha, $feature, true);
}
$residentUnit = (int)$residentRow['unit_id'];
$announcements = new \Source\Services\Erp\Announcements($pdo);
$announcements->announce($admin, ['condominium_id' => $alpha, 'subject' => 'Manutenção da caixa d\'água', 'body' => "Na próxima terça-feira, das 8h às 12h, a água será interrompida para a limpeza semestral da caixa d'água.\nObrigado pela compreensão.", 'audience' => 'all']);
$announcements->announce($admin, ['condominium_id' => $alpha, 'subject' => 'Novo horário da academia', 'body' => 'A partir deste mês a academia funciona das 6h às 22h, todos os dias.', 'audience' => 'all']);
$pdo->exec("UPDATE erp_announcements SET send_at = send_at - INTERVAL 1 DAY WHERE condominium_id = {$alpha}");
$save('deliveries', ['condominium_id' => $alpha, 'unit_id' => $residentUnit, 'recipient' => (string)$residentRow['name'], 'carrier' => 'mercado_livre', 'tracking_code' => 'MEL123456789BR', 'received_on' => $date(0), 'received_time' => '10:42']);
$save('deliveries', ['condominium_id' => $alpha, 'unit_id' => $residentUnit, 'recipient' => (string)$residentRow['name'], 'carrier' => 'correios', 'tracking_code' => 'QB123456789BR', 'received_on' => $date(-6), 'received_time' => '15:10', 'picked_up_on' => $date(-6), 'picked_up_by' => (string)$residentRow['name'], 'status' => 'picked_up']);
$save('visit_forecasts', ['condominium_id' => $alpha, 'unit_id' => $residentUnit, 'visitor_name' => 'Técnico da internet', 'expected_on' => $date(0), 'authorized_by' => (string)$residentRow['name'], 'notes' => 'Chega à tarde']);
$assemblies = new \Source\Services\Erp\Assemblies($pdo);
$assembly = $assemblies->create($admin, ['condominium_id' => $alpha, 'kind' => 'ordinaria', 'mode' => 'hibrida', 'title' => 'Assembleia geral ordinária ' . $today->format('Y'),
    'location' => 'Salão de festas e sala virtual', 'first_call_at' => $date(15) . ' 19:00', 'second_call_at' => $date(15) . ' 19:30',
    'items' => [['title' => 'Prestação de contas do ano', 'vote_basis' => 'fraction', 'quorum' => 'simple'], ['title' => 'Previsão orçamentária', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
        ['title' => 'Eleição do síndico', 'vote_basis' => 'unit', 'quorum' => 'simple']]]);
$pdo->prepare("UPDATE erp_assemblies SET status = 'convened', convened_at = NOW() WHERE id = ?")->execute([(int)$assembly['id']]);

echo "Base de demonstração pronta em {$database}: " . count($ids) . " condomínios fictícios.\n";
echo "HELP_USER=ajuda@connect.demo\nHELP_PASSWORD={$password}\nHELP_RESIDENT=morador@connect.demo\n";

/** CPF fictício com dígitos verificadores válidos (o cadastro confere o documento). */
function cpf(int $seed): string
{
    $digits = str_split(str_pad((string)(100000000 + $seed * 7919 % 800000000), 9, '0', STR_PAD_LEFT));
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($c = 0; $c < $t; $c++) {
            $sum += (int)$digits[$c] * (($t + 1) - $c);
        }
        $digits[] = (string)((10 * $sum) % 11 % 10);
    }
    return implode('', $digits);
}

/** CNPJ fictício com dígitos verificadores válidos. */
function cnpj(int $seed): string
{
    $digits = str_split(str_pad((string)(10000000 + $seed * 104729 % 89999999), 8, '0', STR_PAD_LEFT) . '0001');
    foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += (int)$digits[$i] * $weight;
        }
        $rest = $sum % 11;
        $digits[] = (string)($rest < 2 ? 0 : 11 - $rest);
    }
    return implode('', $digits);
}
