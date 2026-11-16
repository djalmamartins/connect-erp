<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Privacy\PersonalDataAnonymizer;

/** ADR 0330: anonimização de quem saiu (LGPD), parte 2 do ADR 0328. */
final class PersonalDataAnonymizerTest extends TestCase
{
    private const CPF = '52998224725';
    private const TODAY = '2026-11-16';
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
                valid_until TEXT, notes TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_vehicles (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, plate TEXT, kind TEXT, model TEXT, color TEXT,
                parking_spot TEXT, owner_name TEXT, notes TEXT, status TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_pets (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, species TEXT, size TEXT, breed TEXT,
                tutor_name TEXT, vaccinated INTEGER, notes TEXT, status TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE studio_support_tickets (id INTEGER PRIMARY KEY, protocol TEXT, subject TEXT, message TEXT, area TEXT, origin TEXT, condominium_id INTEGER, unit_id INTEGER,
                requester_name TEXT, requester_email TEXT, requester_phone TEXT, access_token TEXT, status TEXT, created_at TEXT, resolved_at TEXT)',
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
            (1, 1, 101, 10, 'Cota', 50000, '2026-11-10', 'active'), (2, 1, 102, 12, 'Cota', 50000, '2026-11-10', 'active')");
        $this->pdo->exec("INSERT INTO erp_visitors (id, condominium_id, unit_label, name, document, notes, status) VALUES (1, 1, 'A-102', 'José', '" . self::CPF . "', 'nota', 'active'), (2, 1, 'A-102', 'Outro', '11144477735', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_vehicles (id, condominium_id, unit_id, unit_label, plate, owner_name, notes, status) VALUES
            (1, 1, 101, 'A-101', 'ABC1D23', 'JOSE DA SILVA', 'nota', 'active'), (2, 1, 101, 'A-101', 'XYZ9K87', 'Ana Silva', NULL, 'active'), (3, 1, 102, 'A-102', 'QWE1R23', 'José da Silva', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_pets (id, condominium_id, unit_id, unit_label, name, tutor_name, status) VALUES (1, 1, 101, 'A-101', 'Rex', 'José  da Silva', 'active')");
        $this->pdo->exec("INSERT INTO studio_support_tickets (id, protocol, subject, condominium_id, requester_email, access_token, status) VALUES
            (1, 'HD-1', 'Vazamento', 1, 'jose@exemplo.test', 'tok', 'open'), (2, 'HD-2', 'Outro', 2, 'jose@exemplo.test', 'tok', 'open')");
        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email, document, password, status) VALUES
            (50, 'José', 'Silva', 'jose@exemplo.test', '" . self::CPF . "', 'hash', 'confirmed'), (51, 'Maria', 'Souza', 'maria@exemplo.test', NULL, 'hash', 'confirmed')");
    }

    private function leave(): void
    {
        $this->pdo->exec("UPDATE erp_residents SET ends_on = '2026-10-31' WHERE id = 10");
        $this->pdo->exec("UPDATE erp_entries SET paid_on = '2026-11-05' WHERE id = 1");
    }

    public function testPreviewChangesNothingAndListsBlockers(): void
    {
        $out = (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => self::CPF], self::TODAY);
        self::assertFalse($out['confirmed']);
        self::assertSame(['still_linked', 'open_receivable'], array_column($out['blockers'], 'code'));
        self::assertSame([10], $out['plan']['residents']);
        self::assertSame('José da Silva', $this->pdo->query('SELECT name FROM erp_residents WHERE id = 10')->fetchColumn());
    }

    public function testRefusesWhileStillLivingOrOwing(): void
    {
        $this->expectException(DomainException::class);
        (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => self::CPF, 'reason' => 'Pedido do titular nº 12', 'confirm' => true], self::TODAY);
    }

    public function testRequiresReasonToConfirm(): void
    {
        $this->leave();
        $this->expectException(InvalidArgumentException::class);
        (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => self::CPF, 'reason' => 'curto', 'confirm' => true], self::TODAY);
    }

    public function testAnonymizesInScopeAndKeepsFinance(): void
    {
        $this->leave();
        $out = (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => self::CPF, 'reason' => 'Pedido do titular nº 12', 'confirm' => true], self::TODAY);
        self::assertTrue($out['confirmed']);
        $r = $this->pdo->query('SELECT name, document, email, phone, notes, user_id, status FROM erp_residents WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['name' => 'Titular anonimizado #10', 'document' => null, 'email' => null, 'phone' => null, 'notes' => null, 'user_id' => null, 'status' => 'inactive'], $r);
        self::assertSame('José da Silva', $this->pdo->query('SELECT name FROM erp_residents WHERE id = 11')->fetchColumn(), 'outro condomínio fora do escopo');
        self::assertSame('Maria Souza', $this->pdo->query('SELECT name FROM erp_residents WHERE id = 12')->fetchColumn());
        self::assertSame([null, 'Titular anonimizado'], array_values($this->pdo->query('SELECT document, name FROM erp_visitors WHERE id = 1')->fetch(PDO::FETCH_ASSOC)));
        self::assertSame('Titular anonimizado', $this->pdo->query('SELECT owner_name FROM erp_vehicles WHERE id = 1')->fetchColumn());
        self::assertSame('Ana Silva', $this->pdo->query('SELECT owner_name FROM erp_vehicles WHERE id = 2')->fetchColumn());
        self::assertSame('Titular anonimizado', $this->pdo->query('SELECT tutor_name FROM erp_pets WHERE id = 1')->fetchColumn());
        self::assertSame([null, 'Vazamento'], array_values($this->pdo->query('SELECT requester_email, subject FROM studio_support_tickets WHERE id = 1')->fetch(PDO::FETCH_ASSOC)));
        self::assertSame('jose@exemplo.test', $this->pdo->query('SELECT requester_email FROM studio_support_tickets WHERE id = 2')->fetchColumn());
        self::assertSame(['50000', '10'], array_map('strval', array_values($this->pdo->query('SELECT amount_cents, payer_resident_id FROM erp_entries WHERE id = 1')->fetch(PDO::FETCH_ASSOC))), 'conta fica');
        self::assertSame('jose@exemplo.test', $this->pdo->query('SELECT email FROM users WHERE id = 50')->fetchColumn(), 'conta de acesso não muda');
    }

    public function testNothingFound(): void
    {
        $this->expectException(DomainException::class);
        (new PersonalDataAnonymizer($this->pdo))->apply([1], ['email' => 'ninguem@exemplo.test'], self::TODAY);
    }
}
