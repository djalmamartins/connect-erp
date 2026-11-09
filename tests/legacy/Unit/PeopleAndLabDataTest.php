<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\CondominiumValidator;
use Source\Services\Erp\InvitationRules;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\People;
use Source\Services\Erp\Registry\RegistryNotFound;

/** ADR 0162: CNPJ do condomínio opcional, busca e vínculo de pessoas e dados de exemplo do laboratório. */
final class PeopleAndLabDataTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT,
                document TEXT, email TEXT, phone TEXT, starts_on TEXT, created_by INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE system_audit_logs (id INTEGER PRIMARY KEY, users_id INTEGER, action TEXT, entity TEXT, created_at TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Residencial Aurora'), (2, 'Comercial Centro'), (3, 'Outro Prédio')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label) VALUES (11, 1, '101'), (12, 1, '102'), (21, 2, 'Sala 1'), (22, 2, 'Sala 2'), (31, 3, '301')");
        $insert = $this->pdo->prepare('INSERT INTO erp_residents (id, condominium_id, unit_id, unit_label, relationship, name, document, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 1, 11, '101', 'owner', 'Ana Souza', '52998224725', 'ana@morador.test', '11999990000'],
            [2, 2, 21, 'Sala 1', 'owner', 'Ana Souza', '52998224725', 'ana@morador.test', '11999990000'],
            [3, 2, 22, 'Sala 2', 'tenant', 'Loja Exemplo Ltda', '11222333000181', null, null],
            [4, 3, 31, '301', 'owner', 'Anastácia Fora', '11144477735', 'fora@outro.test', '21988887777'],
            [5, 1, 12, '102', 'resident', 'Ana Moradora', null, null, null],
        ] as $row) {
            $insert->execute($row);
        }
    }

    public function testCondominiumDocumentIsOptionalButValidatedWhenFilled(): void
    {
        $validator = new CondominiumValidator();
        $base = ['name' => 'Edifício Sem CNPJ', 'legal_name' => 'Edifício Sem CNPJ', 'zip_code' => '01001000', 'street' => 'Rua A', 'number' => '1',
            'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP'];
        $empty = $validator->validate($base + ['document' => '']);
        self::assertArrayNotHasKey('document', $empty['errors']);
        self::assertNull($empty['data']['document'], 'Vazio vira NULL: não colide no índice único.');
        self::assertSame('11222333000181', $validator->validate($base + ['document' => '11.222.333/0001-81'])['data']['document']);
        self::assertSame('CNPJ inválido.', $validator->validate($base + ['document' => '11.222.333/0001-82'])['errors']['document'] ?? null);
        self::assertSame('CNPJ inválido.', $validator->validate($base + ['document' => 'sem número'])['errors']['document'] ?? null, 'Texto sem dígito não passa calado.');
        self::assertTrue(InvitationRules::validDocument('529.982.247-25'), 'O CPF/CNPJ do morador no convite continua obrigatório e validado.');
        self::assertFalse(InvitationRules::validDocument(null));
    }

    public function testSearchCriteriaMaskAndType(): void
    {
        self::assertSame(['q' => 'Ana', 'role' => 'any', 'limit' => 20, 'digits' => null], People::criteria('  Ana ', null, null));
        self::assertSame('52998224725', People::criteria('529.982.247-25', 'owner', '5')['digits']);
        self::assertSame(20, People::criteria('Ana', 'tenant', 999)['limit'], 'Limite máximo de 20.');
        foreach ([['an', 'any'], ['', 'any'], ['Ana', 'admin']] as [$q, $role]) {
            try {
                People::criteria($q, $role, 10);
                self::fail("Aceitou q={$q} role={$role}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('***.982.247-**', People::maskDocument('52998224725'));
        self::assertSame('**.222.333/****-**', People::maskDocument('11.222.333/0001-81'));
        self::assertNull(People::maskDocument(null));
        self::assertSame(['PF', 'PJ'], [People::personType('52998224725'), People::personType('11222333000181')]);
    }

    public function testSearchGroupsByPersonAndHidesOutOfScopeLinks(): void
    {
        $result = (new People($this->pdo, new CondominiumScope($this->pdo)))->search($this->user(), 'Ana', 'any', 20);
        self::assertSame(['Ana Souza'], array_column($result, 'name'), 'Morador (resident) não entra; a pessoa aparece uma vez; busca por nome não mostra quem está só fora do escopo.');
        $ana = $result[0];
        self::assertSame(['id', 'name', 'document_masked', 'person_type', 'links'], array_keys($ana), 'Contrato exato da resposta.');
        self::assertSame([1, '***.982.247-**', 'PF'], [$ana['id'], $ana['document_masked'], $ana['person_type']]);
        self::assertSame([
            ['unit_id' => 11, 'unit_label' => '101', 'condominium_slug' => 'residencial-aurora', 'condominium_name' => 'Residencial Aurora', 'role' => 'owner'],
            ['unit_id' => 21, 'unit_label' => 'Sala 1', 'condominium_slug' => 'comercial-centro', 'condominium_name' => 'Comercial Centro', 'role' => 'owner'],
        ], $ana['links'], 'A mesma pessoa em dois condomínios.');
        $outside = (new People($this->pdo, new CondominiumScope($this->pdo)))->search($this->user(), '111.444.777-35', 'any', 20);
        self::assertSame(['Anastácia Fora'], array_column($outside, 'name'), 'Pelo documento completo, a pessoa de fora do escopo aparece.');
        self::assertSame([], $outside[0]['links'], 'Fora do escopo: só nome e documento mascarado.');
        $json = (string)json_encode($result);
        foreach (['@', '99999', '88887777', '52998224725', 'Outro Prédio'] as $leak) {
            self::assertStringNotContainsString($leak, $json, 'Vazou: ' . $leak);
        }
        $byDocument = (new People($this->pdo, new CondominiumScope($this->pdo)))->search($this->user(), '11.222.333/0001-81', 'tenant', 20);
        self::assertSame([['id' => 3, 'name' => 'Loja Exemplo Ltda', 'document_masked' => '**.222.333/****-**', 'person_type' => 'PJ',
            'links' => [['unit_id' => 22, 'unit_label' => 'Sala 2', 'condominium_slug' => 'comercial-centro', 'condominium_name' => 'Comercial Centro', 'role' => 'tenant']]]], $byDocument);
        self::assertSame([], (new People($this->pdo, new CondominiumScope($this->pdo)))->search($this->user(), '5299822', 'any', 20), 'Documento é igualdade, não prefixo.');
    }

    public function testLinkManyUnitsIsIdempotentAndScoped(): void
    {
        $people = new People($this->pdo, new CondominiumScope($this->pdo));
        $first = $people->link($this->user(), 3, ['unit_ids' => [21, '22', 21], 'role' => 'tenant', 'starts_at' => '2026-11-01']);
        self::assertSame(3, $first['person_id']);
        self::assertSame([21], array_column($first['created'], 'unit_id'), 'Sala 2 já tinha o vínculo: não duplica.');
        self::assertSame([22], $first['existing']);
        $again = $people->link($this->user(), 3, ['unit_ids' => [21, 22], 'role' => 'tenant']);
        self::assertSame([[], [21, 22]], [$again['created'], $again['existing']], 'Repetir não cria nada.');
        $row = $this->pdo->query('SELECT condominium_id, unit_label, relationship, name, document, starts_on FROM erp_residents WHERE unit_id = 21 AND relationship = \'tenant\'')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['condominium_id' => 2, 'unit_label' => 'Sala 1', 'relationship' => 'tenant', 'name' => 'Loja Exemplo Ltda', 'document' => '11222333000181', 'starts_on' => '2026-11-01'], $row);

        try {
            $people->link($this->user(), 4, ['unit_ids' => [12], 'role' => 'owner']);
            self::fail('Vinculou pessoa de fora do escopo sem o documento.');
        } catch (\Source\Services\Erp\Registry\RegistryNotFound) {
        }
        try {
            $people->link($this->user(), 4, ['unit_ids' => [12], 'role' => 'owner', 'document' => '52998224725']);
            self::fail('Vinculou pessoa de fora do escopo com documento de outra pessoa.');
        } catch (\Source\Services\Erp\Registry\RegistryNotFound) {
        }
        $outsider = $people->link($this->user(), 4, ['unit_ids' => [12], 'role' => 'owner', 'document' => '111.444.777-35']);
        $copied = $this->pdo->query('SELECT email, phone FROM erp_residents WHERE id = ' . $outsider['created'][0]['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['email' => null, 'phone' => null], $copied, 'Pessoa de fora do escopo: não traz e-mail nem telefone.');

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents')->fetchColumn();
        foreach ([[1, [31]], [1, [12, 31]], [999, [12]]] as [$person, $units]) {
            try {
                $people->link($this->user(), $person, ['unit_ids' => $units, 'role' => 'owner']);
                self::fail('Vinculou fora do escopo.');
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($count, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents')->fetchColumn(), 'Fora do escopo não grava nada (nem as do escopo).');
        foreach ([['unit_ids' => [], 'role' => 'owner'], ['unit_ids' => [12], 'role' => 'resident'], ['unit_ids' => [12], 'role' => 'owner', 'starts_at' => '2026-13-01'],
            ['unit_ids' => range(1, 51), 'role' => 'owner']] as $bad) {
            try {
                People::linkInput($bad);
                self::fail('Aceitou: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLabGuardRefusesWhenInDoubt(): void
    {
        self::assertNull(LabSampleData::guard('lab', 'connect_erp_lab', 'true'));
        self::assertNull(LabSampleData::guard('development', 'organic_dev', true));
        foreach ([['production', 'connect_erp_lab'], ['', 'connect_erp_lab'], [null, 'connect_lab'], ['testing', 'connect_lab'], ['lab', 'connect_erp'],
            ['lab', ''], ['lab', 'connect_prod_lab'], ['dev', 'erp']] as [$env, $db]) {
            self::assertNotNull(LabSampleData::guard($env, $db, 'true'), json_encode([$env, $db]));
        }
        // ERP_DEMO_DB libera pelo nome exato, sem tirar as outras travas.
        self::assertNull(LabSampleData::guard('lab', 'connect_legacy', 'true', 'connect_legacy'));
        self::assertNull(LabSampleData::guard('dev', 'Connect_Legacy', 'true', ' connect_legacy '));
        self::assertNotNull(LabSampleData::guard('lab', 'connect_legacy', 'true'), 'Sem ERP_DEMO_DB, connect_legacy recusa.');
        self::assertNotNull(LabSampleData::guard('lab', 'connect_legacy_old', 'true', 'connect_legacy'), 'Só igualdade exata.');
        self::assertNotNull(LabSampleData::guard('production', 'connect_legacy', 'true', 'connect_legacy'), 'Produção recusa mesmo liberado.');
        self::assertNotNull(LabSampleData::guard('lab', 'connect_prod', 'true', 'connect_prod'), '"prod" recusa sempre.');
        self::assertNotNull(LabSampleData::guard('lab', 'connect_legacy', 'false', 'connect_legacy'), 'ERP_DEMO_DATA continua obrigatório.');
        foreach ([false, null, '', 'false', '0', 'talvez', [], 'off'] as $flag) {
            self::assertStringContainsString('ERP_DEMO_DATA', (string)LabSampleData::guard('lab', 'connect_erp_lab', $flag), 'ERP_DEMO_DATA desligado ou ambíguo recusa: ' . json_encode($flag));
        }
        $tool = (string)file_get_contents(dirname(__DIR__, 3) . '/tools/lab-dados-exemplo.php');
        self::assertStringContainsString("defined('ERP_DEMO_DATA') ? constant('ERP_DEMO_DATA') : getenv('ERP_DEMO_DATA')", $tool);
    }

    public function testLabPlanCoversTheDelinquencyScenarios(): void
    {
        $plan = LabSampleData::plan('2026-11-09');
        self::assertCount(6, $plan['condominiums'][0]['units'], 'Condomínio de exemplo com 6 unidades.');
        foreach (array_merge(array_column($plan['condominiums'], 'name'), array_column($plan['people'], 'name')) as $name) {
            self::assertStringStartsWith(LabSampleData::MARK . ' ', $name, 'Tudo com a marca.');
        }
        foreach ($plan['people'] as $person) {
            self::assertTrue(InvitationRules::validDocument($person['document']), $person['name']);
        }
        $multi = array_filter($plan['people'], static fn (array $p): bool => count(array_unique(array_column($p['units'], 0))) > 1);
        self::assertNotEmpty($multi, 'Um proprietário com unidades em 2 condomínios.');
        $tenant = array_values(array_filter($plan['people'], static fn (array $p): bool => $p['role'] === 'tenant'))[0];
        self::assertCount(2, $tenant['units'], 'Inquilino com duas salas.');
        $overdue = [];
        foreach ($plan['entries'] as $entry) {
            if ($entry['paid_on'] === null && $entry['due_on'] < '2026-11-09') {
                $overdue[] = (int)((strtotime('2026-11-09') - strtotime($entry['due_on'])) / 86400);
            }
        }
        sort($overdue);
        self::assertSame([30, 60, 120], $overdue);
        self::assertCount(1, array_filter($plan['entries'], static fn (array $e): bool => $e['paid_on'] !== null), 'Uma paga.');
        self::assertCount(1, array_filter($plan['entries'], static fn (array $e): bool => $e['paid_on'] === null && $e['due_on'] > '2026-11-09'), 'Uma em dia.');
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/LabSampleData.php');
        foreach (['BankAdapters', 'Email', 'mail(', 'curl_', 'invoices'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'Sem banco real, boleto nem e-mail: ' . $forbidden);
        }
        self::assertSame(6, substr_count($source, 'LIKE " . $this->pdo->quote('), 'remove() só apaga o que tem a marca, em cada tabela.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
