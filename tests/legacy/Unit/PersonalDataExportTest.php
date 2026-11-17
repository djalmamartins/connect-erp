<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Privacy\PersonalDataExport;

/** ADR 0328: relatório dos dados pessoais de um titular (LGPD art. 18, II). */
final class PersonalDataExportTest extends TestCase
{
    private const CPF = '52998224725';
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                email TEXT, phone TEXT, starts_on TEXT, ends_on TEXT, billing_by_email INTEGER, notes TEXT, status TEXT, user_id INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_resident_invitations (id INTEGER PRIMARY KEY, condominium_id INTEGER, resident_id INTEGER, token_hash TEXT, status TEXT, expires_at TEXT,
                accepted_at TEXT, revoked_at TEXT, created_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, payer_resident_id INTEGER, description TEXT, amount_cents INTEGER,
                competence TEXT, due_on TEXT, paid_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_label TEXT, name TEXT, document TEXT, phone TEXT, permanent_access INTEGER,
                valid_until TEXT, notes TEXT, status TEXT, created_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_vehicles (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, plate TEXT, kind TEXT, model TEXT, color TEXT,
                parking_spot TEXT, owner_name TEXT, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_pets (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, species TEXT, size TEXT, breed TEXT,
                tutor_name TEXT, vaccinated INTEGER, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE studio_support_tickets (id INTEGER PRIMARY KEY, protocol TEXT, subject TEXT, message TEXT, area TEXT, origin TEXT, condominium_id INTEGER, unit_id INTEGER,
                requester_name TEXT, requester_email TEXT, requester_phone TEXT, access_token TEXT, status TEXT, created_at TEXT, resolved_at TEXT)',
            'CREATE TABLE erp_access_logs (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, person_name TEXT, person_document TEXT, kind TEXT, plate TEXT,
                entered_on TEXT, entered_time TEXT, left_time TEXT, notes TEXT, status TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visit_forecasts (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, visitor_name TEXT, visitor_document TEXT, expected_on TEXT,
                expected_until TEXT, notes TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_board_members (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, role TEXT, name TEXT, document TEXT, unit_label TEXT,
                term_starts_on TEXT, term_ends_on TEXT, email TEXT, phone TEXT, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_moves (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, resident_name TEXT, kind TEXT, scheduled_on TEXT, period TEXT, notes TEXT,
                status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT, reserved_on TEXT,
                starts_time TEXT, ends_time TEXT, fee_cents INTEGER, notes TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_waitlist (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT, desired_on TEXT, notes TEXT,
                called_at TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_assemblies (id INTEGER PRIMARY KEY, condominium_id INTEGER, title TEXT, first_call_at TEXT)',
            'CREATE TABLE erp_assembly_attendance (id INTEGER PRIMARY KEY, assembly_id INTEGER, unit_id INTEGER, attendee_name TEXT, is_proxy INTEGER, checked_in_at TEXT)',
            'CREATE TABLE erp_collection_contacts (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, channel TEXT, outcome TEXT, contact_name TEXT, promise_on TEXT,
                promise_cents INTEGER, next_action_on TEXT, note TEXT, created_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, document TEXT, password TEXT, status TEXT, created_at TEXT, updated_at TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque')");
        $this->pdo->exec("INSERT INTO erp_residents (id, condominium_id, unit_id, unit_label, relationship, name, document, email, notes, status, user_id) VALUES
            (10, 1, 101, 'A-101', 'owner', 'José da Silva', '" . self::CPF . "', 'Jose@Exemplo.test', 'nota interna', 'active', 50),
            (11, 2, 201, 'B-201', 'tenant', 'José da Silva', '529.982.247-25', 'outro@exemplo.test', NULL, 'active', NULL),
            (12, 1, 102, 'A-102', 'owner', 'Maria Souza', '11144477735', 'maria@exemplo.test', NULL, 'active', 51)");
        $this->pdo->exec("INSERT INTO erp_resident_invitations (id, condominium_id, resident_id, token_hash, status) VALUES (1, 1, 10, 'segredo', 'accepted'), (2, 1, 12, 'x', 'pending')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, unit_id, payer_resident_id, description, amount_cents, due_on, status) VALUES
            (1, 1, 101, 10, 'Cota', 50000, '2026-11-10', 'open'), (2, 1, 102, 12, 'Cota', 50000, '2026-11-10', 'open')");
        $this->pdo->exec("INSERT INTO erp_visitors (id, condominium_id, unit_label, name, document, notes, status) VALUES (1, 1, 'A-102', 'José', '" . self::CPF . "', 'nota', 'active'), (2, 1, 'A-102', 'Outro', '11144477735', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_vehicles (id, condominium_id, unit_id, unit_label, plate, owner_name, notes, status) VALUES
            (1, 1, 101, 'A-101', 'ABC1D23', 'JOSE DA SILVA', 'nota', 'active'), (2, 1, 101, 'A-101', 'XYZ9K87', 'Ana Silva', NULL, 'active'), (3, 1, 102, 'A-102', 'QWE1R23', 'José da Silva', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_pets (id, condominium_id, unit_id, unit_label, name, tutor_name, status) VALUES (1, 1, 101, 'A-101', 'Rex', 'José  da Silva', 'active')");
        $this->pdo->exec("INSERT INTO studio_support_tickets (id, protocol, subject, condominium_id, requester_email, access_token, status) VALUES
            (1, 'HD-1', 'Vazamento', 1, 'jose@exemplo.test', 'tok', 'open'), (2, 'HD-2', 'Outro', 2, 'jose@exemplo.test', 'tok', 'open')");
        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email, document, password, status) VALUES
            (50, 'José', 'Silva', 'jose@exemplo.test', '" . self::CPF . "', 'hash', 'confirmed'), (51, 'Maria', 'Souza', 'maria@exemplo.test', NULL, 'hash', 'confirmed')");
    }

    public function testCriteriaValidation(): void
    {
        self::assertSame([self::CPF, null], PersonalDataExport::criteria('529.982.247-25', ''));
        self::assertSame([null, 'jose@exemplo.test'], PersonalDataExport::criteria(null, ' Jose@Exemplo.TEST '));
        foreach ([[null, null], ['123', null], [null, 'nao-e-email'], [['x'], null]] as [$doc, $mail]) {
            try {
                PersonalDataExport::criteria($doc, $mail);
                self::fail('deveria recusar');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCollectsEverythingInScopeByDocument(): void
    {
        $data = (new PersonalDataExport($this->pdo))->collect([1], self::CPF, null);
        self::assertSame([10], array_map('intval', array_column($data['residents']['rows'], 'id')), 'só o condomínio do escopo');
        self::assertSame([1], array_map('intval', array_column($data['invitations']['rows'], 'id')));
        self::assertSame([1], array_map('intval', array_column($data['receivables']['rows'], 'id')));
        self::assertSame([1], array_map('intval', array_column($data['visitors']['rows'], 'id')));
        self::assertSame([1], array_map('intval', array_column($data['vehicles']['rows'], 'id')), 'mesmo nome e mesma unidade');
        self::assertSame([1], array_map('intval', array_column($data['pets']['rows'], 'id')));
        self::assertSame([1], array_map('intval', array_column($data['tickets']['rows'], 'id')), 'e-mail do cadastro, só no escopo');
        self::assertSame([50], array_map('intval', array_column($data['account']['rows'], 'id')));
        self::assertSame(8, $data['total']);
    }

    public function testNeverExportsSecretsOrInternalNotes(): void
    {
        $json = (string)json_encode((new PersonalDataExport($this->pdo))->collect([1, 2], self::CPF, 'jose@exemplo.test', true));
        foreach (['"password"', '"token_hash"', '"access_token"', '"notes"', 'segredo', 'nota interna'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testFormattedDocumentAndOtherCondominiumWhenInScope(): void
    {
        $data = (new PersonalDataExport($this->pdo))->collect([1, 2], self::CPF, null);
        self::assertSame([10, 11], array_map('intval', array_column($data['residents']['rows'], 'id')));
        self::assertSame([1, 2], array_map('intval', array_column($data['tickets']['rows'], 'id')));
    }

    public function testAccountOnlyWithLinkInScopeOrGlobalAccess(): void
    {
        $export = new PersonalDataExport($this->pdo);
        self::assertNull($export->collect([2], null, 'jose@exemplo.test')['account'], 'sem vínculo no escopo');
        self::assertSame([50], array_map('intval', array_column($export->collect([2], null, 'jose@exemplo.test', true)['account']['rows'], 'id')));
    }

    public function testEmptyScopeReturnsNothing(): void
    {
        $data = (new PersonalDataExport($this->pdo))->collect([], self::CPF, 'jose@exemplo.test', true);
        self::assertSame(0, $data['total']);
        self::assertSame([], $data['residents']['rows']);
    }

    /** ADR 0351: portaria, conselho e registros que guardam só o nome digitado na unidade. */
    public function testFindsGatehouseBoardAndNameOnlyRecords(): void
    {
        $cpf = self::CPF;
        $this->pdo->exec("INSERT INTO erp_access_logs (id, condominium_id, unit_id, person_name, person_document, notes, status) VALUES
            (1, 1, 102, 'José', '{$cpf}', 'nota interna', 'active'), (2, 1, 102, 'Outro', '11144477735', NULL, 'active'), (3, 2, 201, 'José', '{$cpf}', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_visit_forecasts (id, condominium_id, unit_id, visitor_name, visitor_document, status) VALUES (1, 1, 102, 'José', '529.982.247-25', 'active')");
        $this->pdo->exec("INSERT INTO erp_board_members (id, condominium_id, role, name, document, email, notes, status) VALUES
            (1, 1, 'sindico', 'José da Silva', NULL, 'jose@exemplo.test', 'nota interna', 'active'), (2, 1, 'conselheiro', 'Maria', '11144477735', NULL, NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_moves (id, condominium_id, unit_id, resident_name, notes, status) VALUES (1, 1, 101, 'jose da silva', 'nota interna', 'active'), (2, 1, 101, 'Ana', NULL, 'active'), (3, 1, 102, 'José da Silva', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_reservations (id, condominium_id, unit_id, resident_name, status) VALUES (1, 1, 101, 'José da Silva', 'active')");
        $this->pdo->exec("INSERT INTO erp_waitlist (id, condominium_id, unit_id, resident_name, status) VALUES (1, 1, 101, 'JOSÉ DA SILVA', 'active')");
        $this->pdo->exec("INSERT INTO erp_assemblies (id, condominium_id, title) VALUES (1, 1, 'AGO'), (2, 2, 'AGE')");
        $this->pdo->exec("INSERT INTO erp_assembly_attendance (id, assembly_id, unit_id, attendee_name) VALUES (1, 1, 101, 'José da Silva'), (2, 2, 101, 'José da Silva')");
        $this->pdo->exec("INSERT INTO erp_collection_contacts (id, condominium_id, unit_id, contact_name, note) VALUES (1, 1, 101, 'José da Silva', 'nota interna'), (2, 1, 101, 'Síndico', NULL)");

        $data = (new PersonalDataExport($this->pdo))->collect([1], $cpf, 'jose@exemplo.test');
        $ids = static fn (string $group): array => array_map('intval', array_column($data[$group]['rows'], 'id'));
        self::assertSame([1], $ids('access_logs'), 'pelo documento, só no escopo');
        self::assertSame([1], $ids('visit_forecasts'), 'documento formatado também');
        self::assertSame([1], $ids('board_members'), 'pelo e-mail');
        self::assertSame([1], $ids('moves'), 'mesmo nome na mesma unidade');
        self::assertSame([1], $ids('reservations'));
        self::assertSame([1], $ids('waitlist'));
        self::assertSame([1], $ids('assembly_attendance'), 'assembleia do condomínio do escopo');
        self::assertSame([1], $ids('collection_contacts'));
        self::assertSame(16, $data['total']);
        $json = (string)json_encode($data);
        self::assertStringNotContainsString('nota interna', $json);
        self::assertStringNotContainsString('"note"', $json);
    }
}
