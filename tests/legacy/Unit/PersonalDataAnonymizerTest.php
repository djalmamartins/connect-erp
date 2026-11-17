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
            'CREATE TABLE talk_contacts (id INTEGER PRIMARY KEY, phone TEXT UNIQUE, name TEXT, erp_person_id INTEGER, erp_snapshot TEXT, created_at TEXT, updated_at TEXT,
                blocked_at TEXT, opt_out_at TEXT)',
            'CREATE TABLE talk_contact_notes (id INTEGER PRIMARY KEY, contact_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT)',
            'CREATE TABLE talk_conversations (id INTEGER PRIMARY KEY, protocol TEXT, contact_id INTEGER, status TEXT, subject TEXT, helpdesk_ticket_id INTEGER,
                opened_at TEXT, closed_at TEXT, last_message_at TEXT)',
            'CREATE TABLE talk_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, direction TEXT, type TEXT, body TEXT, media_name TEXT, created_at TEXT)',
            'CREATE TABLE talk_outbox (id INTEGER PRIMARY KEY, message_id INTEGER, to_phone TEXT, status TEXT)',
            'CREATE TABLE talk_campaign_recipients (id INTEGER PRIMARY KEY, campaign_id INTEGER, contact_id INTEGER, phone TEXT, name TEXT, params TEXT, status TEXT,
                sent_at TEXT, replied_at TEXT)',
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

    /** ADR 0351: portaria, mudanças, reservas, espera e cobrança anonimizadas; conselho e presença em assembleia ficam. */
    public function testAnonymizesGatehouseAndNameOnlyRecordsButKeepsMinutes(): void
    {
        $this->leave();
        $cpf = self::CPF;
        $this->pdo->exec("INSERT INTO erp_access_logs (id, condominium_id, unit_id, person_name, person_document, plate, notes, status) VALUES (1, 1, 102, 'José', '{$cpf}', 'ABC1D23', 'nota', 'active'), (2, 2, 201, 'José', '{$cpf}', NULL, NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_visit_forecasts (id, condominium_id, unit_id, visitor_name, visitor_document, notes, status) VALUES (1, 1, 102, 'José', '{$cpf}', 'nota', 'active')");
        $this->pdo->exec("INSERT INTO erp_moves (id, condominium_id, unit_id, resident_name, notes, status) VALUES (1, 1, 101, 'José da Silva', 'nota', 'done'), (2, 1, 101, 'Ana', NULL, 'done')");
        $this->pdo->exec("INSERT INTO erp_reservations (id, condominium_id, unit_id, resident_name, fee_cents, status) VALUES (1, 1, 101, 'jose da silva', 5000, 'active')");
        $this->pdo->exec("INSERT INTO erp_waitlist (id, condominium_id, unit_id, resident_name, status) VALUES (1, 1, 101, 'José da Silva', 'served')");
        $this->pdo->exec("INSERT INTO erp_collection_contacts (id, condominium_id, unit_id, contact_name, promise_cents, note) VALUES (1, 1, 101, 'José da Silva', 1000, 'prometeu pagar')");
        $this->pdo->exec("INSERT INTO erp_board_members (id, condominium_id, role, name, document, status) VALUES (1, 1, 'conselheiro', 'José da Silva', '{$cpf}', 'inactive')");
        $this->pdo->exec("INSERT INTO erp_assemblies (id, condominium_id, title) VALUES (1, 1, 'AGO')");
        $this->pdo->exec("INSERT INTO erp_assembly_attendance (id, assembly_id, unit_id, attendee_name) VALUES (1, 1, 101, 'José da Silva')");

        $preview = (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => $cpf], self::TODAY);
        self::assertSame([1], $preview['plan']['access_logs']);
        self::assertSame(['receivables' => 1, 'board_members' => 1, 'assembly_attendance' => 1, 'talk_messages' => 0], $preview['plan']['kept']);

        (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => $cpf, 'reason' => 'Pedido do titular nº 13', 'confirm' => true], self::TODAY);
        $row = fn (string $sql): array => array_values($this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC));
        self::assertSame(['Titular anonimizado', null, null, 'ABC1D23'], $row('SELECT person_name, person_document, notes, plate FROM erp_access_logs WHERE id = 1'));
        self::assertSame(['José', $cpf], $row('SELECT person_name, person_document FROM erp_access_logs WHERE id = 2'), 'outro condomínio fora do escopo');
        self::assertSame(['Titular anonimizado', null, null], $row('SELECT visitor_name, visitor_document, notes FROM erp_visit_forecasts WHERE id = 1'));
        self::assertSame(['Titular anonimizado', null], $row('SELECT resident_name, notes FROM erp_moves WHERE id = 1'));
        self::assertSame(['Ana'], $row('SELECT resident_name FROM erp_moves WHERE id = 2'));
        self::assertSame(['Titular anonimizado', '5000'], array_map(static fn ($v) => $v === null ? null : (string)$v, $row('SELECT resident_name, fee_cents FROM erp_reservations WHERE id = 1')));
        self::assertSame(['Titular anonimizado'], $row('SELECT resident_name FROM erp_waitlist WHERE id = 1'));
        self::assertSame(['Titular anonimizado', null, '1000'], array_map(static fn ($v) => $v === null ? null : (string)$v, $row('SELECT contact_name, note, promise_cents FROM erp_collection_contacts WHERE id = 1')));
        self::assertSame(['José da Silva', $cpf], $row('SELECT name, document FROM erp_board_members WHERE id = 1'), 'mandato fica');
        self::assertSame(['José da Silva'], $row('SELECT attendee_name FROM erp_assembly_attendance WHERE id = 1'), 'presença na ata fica');
    }

    /** ADR 0353: no Talk o contato perde nome e telefone, as anotações somem e as mensagens ficam. */
    public function testAnonymizesTalkOnlyWithGlobalAccess(): void
    {
        $this->leave();
        $this->pdo->exec("UPDATE erp_residents SET phone = '(31) 99876-5432' WHERE id = 10");
        $this->pdo->exec("INSERT INTO talk_contacts (id, phone, name, erp_snapshot) VALUES (1, '5531998765432', 'José', '{\"unidade\":\"A-101\"}'), (2, '553188887777', 'Outra pessoa', NULL)");
        $this->pdo->exec("INSERT INTO talk_contact_notes (id, contact_id, body) VALUES (1, 1, 'nota interna da equipe')");
        $this->pdo->exec("INSERT INTO talk_conversations (id, protocol, contact_id, status) VALUES (1, 'T-1', 1, 'closed'), (2, 'T-2', 2, 'open')");
        $this->pdo->exec("INSERT INTO talk_messages (id, conversation_id, direction, type, body) VALUES (1, 1, 'in', 'text', 'Bom dia, sou do A-101'), (2, 2, 'in', 'text', 'outra conversa')");
        $this->pdo->exec("INSERT INTO talk_outbox (id, message_id, to_phone, status) VALUES (1, 1, '5531998765432', 'sent'), (2, 2, '553188887777', 'sent')");
        $this->pdo->exec("INSERT INTO talk_campaign_recipients (id, campaign_id, contact_id, phone, name, params, status) VALUES
            (1, 1, NULL, '31998765432', 'José', '{\"nome\":\"José\"}', 'sent'), (2, 1, 2, '553188887777', 'Outra pessoa', NULL, 'sent')");

        $input = ['document' => self::CPF, 'reason' => 'Pedido do titular nº 14', 'confirm' => true];
        $scoped = (new PersonalDataAnonymizer($this->pdo))->apply([1], ['document' => self::CPF], self::TODAY);
        self::assertSame([], $scoped['plan']['talk_contacts']);
        $out = (new PersonalDataAnonymizer($this->pdo))->apply([1], $input, self::TODAY, true);
        self::assertSame([1], $out['plan']['talk_contacts']);
        self::assertSame(1, $out['plan']['kept']['talk_messages']);
        $row = fn (string $sql): array => array_values($this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC));
        self::assertSame(['Titular anonimizado #1', 'anon-1', null, null], $row('SELECT name, phone, erp_person_id, erp_snapshot FROM talk_contacts WHERE id = 1'));
        self::assertSame(['Outra pessoa', '553188887777'], $row('SELECT name, phone FROM talk_contacts WHERE id = 2'));
        self::assertSame('0', (string)$this->pdo->query('SELECT COUNT(*) FROM talk_contact_notes')->fetchColumn());
        self::assertSame(['Bom dia, sou do A-101'], $row('SELECT body FROM talk_messages WHERE id = 1'), 'registro do atendimento fica');
        self::assertSame([''], $row('SELECT to_phone FROM talk_outbox WHERE id = 1'));
        self::assertSame(['553188887777'], $row('SELECT to_phone FROM talk_outbox WHERE id = 2'));
        self::assertSame(['anon-1', null, null], $row('SELECT phone, name, params FROM talk_campaign_recipients WHERE id = 1'));
        self::assertSame(['553188887777'], $row('SELECT phone FROM talk_campaign_recipients WHERE id = 2'));
    }

}
