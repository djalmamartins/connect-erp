<?php

declare(strict_types=1);

namespace Source\Services\Privacy;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Talk\Support\Phone;
use Source\Support\Erp\BrDocument;

/**
 * Relatório dos dados pessoais de um titular (LGPD art. 18, II: acesso aos dados), ADR 0328.
 *
 * A equipe da Connect recebe o pedido do titular, confere a identidade e busca pelo CPF/CNPJ e/ou e-mail. Sai tudo o que o
 * sistema guarda dessa pessoa nos condomínios do escopo de quem pede:
 * - cadastros de morador/proprietário/inquilino (erp_residents) e os convites do portal;
 * - visitantes com o mesmo documento (erp_visitors);
 * - veículos e animais da mesma unidade cujo dono/tutor tem o mesmo nome do cadastro (o cadastro antigo guarda só o nome);
 * - contas a receber em que a pessoa é a pagadora gravada (erp_entries.payer_resident_id);
 * - chamados do Help Desk abertos com o mesmo e-mail;
 * - a conta de acesso (users) com o mesmo e-mail ou documento, sem senha, sem segredo de 2FA e sem tokens;
 * - pelo documento (ADR 0351): entradas e saídas da portaria, visitas previstas e cargos no conselho/síndico (este também
 *   pelo e-mail);
 * - pelo nome do cadastro na mesma unidade (ADR 0351): mudanças, reservas, lista de espera, presença em assembleia e
 *   contatos de cobrança, que guardam só o nome digitado;
 * - no Connect Talk (ADR 0353), pelos telefones dos cadastros e do conselho encontrados: contato, conversas, mensagens e
 *   envios de campanha. O Talk atende todos os condomínios juntos (não tem condominium_id), então só entra para quem tem
 *   acesso a todos os condomínios.
 *
 * Somente leitura. Não sai senha, hash, token, segredo nem anotação interna (notes). Escopo (ADR-002): só condomínios que
 * quem pede acessa; a conta de acesso só aparece se ela tiver algum vínculo dentro desse escopo ou se quem pede tiver acesso
 * a todos. Limite de MAX_ROWS por grupo, com truncated.
 */
final class PersonalDataExport
{
    public const MAX_ROWS = 500;

    /** Grupos acrescentados no ADR 0351 (portaria, conselho e registros que guardam só o nome). */
    public const EXTRA_GROUPS = ['access_logs', 'visit_forecasts', 'board_members', 'moves', 'reservations', 'waitlist', 'assembly_attendance', 'collection_contacts'];

    /** Connect Talk (ADR 0353): só com acesso a todos os condomínios. */
    public const TALK_GROUPS = ['talk_contacts', 'talk_conversations', 'talk_messages', 'talk_campaign_recipients'];

    /** Registros com o nome solto na unidade: tabela => [coluna do nome, colunas do relatório (sem anotações)]. */
    private const BY_NAME = [
        'moves' => ['erp_moves', 'resident_name', 'id, condominium_id, unit_id, resident_name, kind, scheduled_on, period, status, created_at, deleted_at'],
        'reservations' => ['erp_reservations', 'resident_name', 'id, condominium_id, common_area_id, unit_id, resident_name, reserved_on, starts_time, ends_time, fee_cents, status, created_at, deleted_at'],
        'waitlist' => ['erp_waitlist', 'resident_name', 'id, condominium_id, common_area_id, unit_id, resident_name, desired_on, called_at, status, created_at, deleted_at'],
        'collection_contacts' => ['erp_collection_contacts', 'contact_name', 'id, condominium_id, unit_id, channel, outcome, contact_name, promise_on, promise_cents, next_action_on, created_at'],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, mixed> */
    public function export(User $user, mixed $document, mixed $email): array
    {
        [$doc, $mail] = self::criteria($document, $email);
        return $this->collect($this->scope->allowedIds($user), $doc, $mail, $this->scope->hasGlobalAccess($user));
    }

    /**
     * Valida e normaliza a busca: documento com 11 (CPF) ou 14 (CNPJ) dígitos válidos e/ou e-mail válido.
     *
     * @return array{0:?string, 1:?string}
     */
    public static function criteria(mixed $document, mixed $email): array
    {
        $doc = is_scalar($document) ? BrDocument::digits((string)$document) : '';
        $mail = is_scalar($email) ? mb_strtolower(trim((string)$email)) : '';
        if ($doc === '' && $mail === '') {
            throw new InvalidArgumentException('Informe o CPF/CNPJ ou o e-mail do titular.');
        }
        if ($doc !== '' && !BrDocument::isCpf($doc) && !BrDocument::isCnpj($doc)) {
            throw new InvalidArgumentException('CPF ou CNPJ inválido.');
        }
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        return [$doc !== '' ? $doc : null, $mail !== '' ? $mail : null];
    }

    /**
     * @param list<int> $condominiumIds condomínios do escopo
     * @return array<string, mixed>
     */
    public function collect(array $condominiumIds, ?string $document, ?string $email, bool $globalAccess = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $condominiumIds)));
        $empty = ['rows' => [], 'truncated' => false];
        $result = [
            'criteria' => ['document' => $document !== null, 'email' => $email !== null],
            'generated_at' => date('c'),
            'residents' => $empty, 'invitations' => $empty, 'visitors' => $empty, 'vehicles' => $empty, 'pets' => $empty,
            'receivables' => $empty, 'tickets' => $empty, 'account' => null,
        ] + array_fill_keys(self::EXTRA_GROUPS, $empty) + array_fill_keys(self::TALK_GROUPS, $empty);
        if ($ids === []) {
            return $result + ['total' => 0];
        }
        $in = implode(',', $ids);
        $documents = $document !== null ? array_values(array_unique([$document, BrDocument::format($document)])) : [];

        $match = [];
        $params = [];
        if ($documents !== []) {
            $match[] = 'r.document IN (' . implode(',', array_fill(0, count($documents), '?')) . ')';
            $params = array_merge($params, $documents);
        }
        if ($email !== null) {
            $match[] = 'LOWER(r.email) = ?';
            $params[] = $email;
        }
        $residents = $this->rows("SELECT r.id, r.condominium_id, c.name AS condominium, r.unit_id, r.unit_label, r.relationship, r.name, r.document,
                r.email, r.phone, r.starts_on, r.ends_on, r.billing_by_email, r.status, r.user_id, r.created_at, r.updated_at, r.deleted_at
            FROM erp_residents r JOIN condominiums c ON c.id = r.condominium_id
            WHERE r.condominium_id IN ({$in}) AND (" . implode(' OR ', $match) . ') ORDER BY r.condominium_id, r.id', $params);
        $result['residents'] = $residents;
        $residentIds = array_map(static fn (array $r): int => (int)$r['id'], $residents['rows']);

        if ($residentIds !== []) {
            $rin = implode(',', $residentIds);
            $result['invitations'] = $this->rows("SELECT id, condominium_id, resident_id, status, expires_at, accepted_at, revoked_at, created_at
                FROM erp_resident_invitations WHERE resident_id IN ({$rin}) AND condominium_id IN ({$in}) ORDER BY id");
            $result['receivables'] = $this->rows("SELECT id, condominium_id, unit_id, description, amount_cents, competence, due_on, paid_on, status
                FROM erp_entries WHERE payer_resident_id IN ({$rin}) AND condominium_id IN ({$in}) AND deleted_at IS NULL ORDER BY due_on, id");
            [$vehicles, $pets] = $this->belongings($residents['rows'], $in);
            $result['vehicles'] = $vehicles;
            $result['pets'] = $pets;
        }

        if ($documents !== []) {
            $result['visitors'] = $this->rows("SELECT id, condominium_id, unit_label, name, document, phone, permanent_access, valid_until, status, created_at, deleted_at
                FROM erp_visitors WHERE condominium_id IN ({$in}) AND document IN (" . implode(',', array_fill(0, count($documents), '?')) . ') ORDER BY id', $documents);
        }

        $emails = array_values(array_unique(array_filter(array_merge(
            $email !== null ? [$email] : [],
            array_map(static fn (array $r): string => mb_strtolower(trim((string)($r['email'] ?? ''))), $residents['rows'])
        ))));
        if ($emails !== []) {
            $result['tickets'] = $this->rows("SELECT id, protocol, subject, area, origin, condominium_id, unit_id, requester_name, requester_email, requester_phone,
                    status, created_at, resolved_at
                FROM studio_support_tickets WHERE condominium_id IN ({$in}) AND LOWER(requester_email) IN (" . implode(',', array_fill(0, count($emails), '?')) . ') ORDER BY id', $emails);
        }

        if ($documents !== []) {
            $marks = implode(',', array_fill(0, count($documents), '?'));
            $result['access_logs'] = $this->rows("SELECT id, condominium_id, unit_id, person_name, person_document, kind, plate, entered_on, entered_time, left_time, status, deleted_at
                FROM erp_access_logs WHERE condominium_id IN ({$in}) AND person_document IN ({$marks}) ORDER BY id", $documents);
            $result['visit_forecasts'] = $this->rows("SELECT id, condominium_id, unit_id, visitor_name, visitor_document, expected_on, expected_until, status, created_at, deleted_at
                FROM erp_visit_forecasts WHERE condominium_id IN ({$in}) AND visitor_document IN ({$marks}) ORDER BY id", $documents);
        }
        $boardMatch = [];
        $boardParams = [];
        if ($documents !== []) {
            $boardMatch[] = 'document IN (' . implode(',', array_fill(0, count($documents), '?')) . ')';
            $boardParams = $documents;
        }
        if ($email !== null) {
            $boardMatch[] = 'LOWER(email) = ?';
            $boardParams[] = $email;
        }
        $result['board_members'] = $this->rows("SELECT id, condominium_id, unit_id, role, name, document, unit_label, term_starts_on, term_ends_on, email, phone, status, deleted_at
            FROM erp_board_members WHERE condominium_id IN ({$in}) AND (" . implode(' OR ', $boardMatch) . ') ORDER BY id', $boardParams);
        if ($residents['rows'] !== []) {
            foreach ($this->byName($residents['rows'], $in) as $group => $rows) {
                $result[$group] = $rows;
            }
        }

        if ($globalAccess) {
            foreach ($this->talk(array_merge($residents['rows'], $result['board_members']['rows'])) as $group => $rows) {
                $result[$group] = $rows;
            }
        }

        $result['account'] = $this->account($document, $email, $residents['rows'], $globalAccess);
        $result['total'] = count($result['residents']['rows']) + count($result['invitations']['rows']) + count($result['visitors']['rows'])
            + count($result['vehicles']['rows']) + count($result['pets']['rows']) + count($result['receivables']['rows'])
            + count($result['tickets']['rows']) + ($result['account'] !== null ? 1 : 0)
            + array_sum(array_map(static fn (string $g): int => count($result[$g]['rows']), array_merge(self::EXTRA_GROUPS, self::TALK_GROUPS)));
        return $result;
    }

    /**
     * Veículos e animais da mesma unidade com dono/tutor de mesmo nome (sem acento e caixa não importam).
     *
     * @param list<array<string, mixed>> $residents
     * @return array{0: array{rows:list<array<string,mixed>>, truncated:bool}, 1: array{rows:list<array<string,mixed>>, truncated:bool}}
     */
    private function belongings(array $residents, string $in): array
    {
        $vehicles = [];
        $pets = [];
        foreach ($residents as $r) {
            $name = self::normalizeName((string)$r['name']);
            if ($name === '') {
                continue;
            }
            $unitClause = $r['unit_id'] !== null ? 'unit_id = ?' : 'unit_label = ?';
            $unitValue = $r['unit_id'] !== null ? (int)$r['unit_id'] : (string)$r['unit_label'];
            foreach ($this->rows("SELECT id, condominium_id, unit_label, plate, kind, model, color, parking_spot, owner_name, status, deleted_at
                    FROM erp_vehicles WHERE condominium_id = ? AND condominium_id IN ({$in}) AND {$unitClause} ORDER BY id", [(int)$r['condominium_id'], $unitValue])['rows'] as $v) {
                if (self::normalizeName((string)$v['owner_name']) === $name) {
                    $vehicles[(int)$v['id']] = $v;
                }
            }
            foreach ($this->rows("SELECT id, condominium_id, unit_label, name, species, size, breed, tutor_name, vaccinated, status, deleted_at
                    FROM erp_pets WHERE condominium_id = ? AND condominium_id IN ({$in}) AND {$unitClause} ORDER BY id", [(int)$r['condominium_id'], $unitValue])['rows'] as $p) {
                if (self::normalizeName((string)$p['tutor_name']) === $name) {
                    $pets[(int)$p['id']] = $p;
                }
            }
        }
        $cut = static fn (array $rows): array => ['rows' => array_slice(array_values($rows), 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
        return [$cut($vehicles), $cut($pets)];
    }

    /**
     * Registros que guardam só o nome digitado (mudança, reserva, espera, cobrança, presença em assembleia): os da mesma
     * unidade do cadastro com o mesmo nome (sem acento e caixa não importam). Cadastro sem unidade não entra.
     *
     * @param list<array<string, mixed>> $residents
     * @return array<string, array{rows:list<array<string,mixed>>, truncated:bool}>
     */
    private function byName(array $residents, string $in): array
    {
        $found = array_fill_keys(array_merge(array_keys(self::BY_NAME), ['assembly_attendance']), []);
        foreach ($residents as $r) {
            $name = self::normalizeName((string)$r['name']);
            if ($name === '' || $r['unit_id'] === null) {
                continue;
            }
            $where = [(int)$r['condominium_id'], (int)$r['unit_id']];
            foreach (self::BY_NAME as $group => [$table, $column, $columns]) {
                foreach ($this->rows("SELECT {$columns} FROM {$table} WHERE condominium_id = ? AND condominium_id IN ({$in}) AND unit_id = ? ORDER BY id", $where)['rows'] as $row) {
                    if (self::normalizeName((string)$row[$column]) === $name) {
                        $found[$group][(int)$row['id']] = $row;
                    }
                }
            }
            foreach ($this->rows("SELECT t.id, a.condominium_id, t.assembly_id, a.title AS assembly, a.first_call_at, t.unit_id, t.attendee_name, t.is_proxy, t.checked_in_at
                    FROM erp_assembly_attendance t JOIN erp_assemblies a ON a.id = t.assembly_id
                    WHERE a.condominium_id = ? AND a.condominium_id IN ({$in}) AND t.unit_id = ? ORDER BY t.id", $where)['rows'] as $row) {
                if (self::normalizeName((string)$row['attendee_name']) === $name) {
                    $found['assembly_attendance'][(int)$row['id']] = $row;
                }
            }
        }
        return array_map(static fn (array $rows): array => ['rows' => array_slice(array_values($rows), 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS], $found);
    }

    /**
     * Connect Talk pelos telefones dos cadastros encontrados (com e sem DDI e o 9º dígito, como o próprio Talk procura).
     * Sem anotações internas da equipe nem dados do canal.
     *
     * @param list<array<string, mixed>> $people
     * @return array<string, array{rows:list<array<string,mixed>>, truncated:bool}>
     */
    private function talk(array $people): array
    {
        $empty = ['rows' => [], 'truncated' => false];
        $out = array_fill_keys(self::TALK_GROUPS, $empty);
        $phones = [];
        foreach ($people as $p) {
            $phone = trim((string)($p['phone'] ?? ''));
            if (strlen((string)preg_replace('/\D+/', '', $phone)) >= 8) {
                $phones = array_merge($phones, Phone::candidates($phone));
            }
        }
        $phones = array_values(array_unique(array_filter($phones)));
        if ($phones === []) {
            return $out;
        }
        $marks = implode(',', array_fill(0, count($phones), '?'));
        $out['talk_contacts'] = $this->rows("SELECT id, phone, name, erp_snapshot, created_at, updated_at, blocked_at, opt_out_at
            FROM talk_contacts WHERE phone IN ({$marks}) ORDER BY id", $phones);
        $contactIds = array_map(static fn (array $c): int => (int)$c['id'], $out['talk_contacts']['rows']);
        $out['talk_campaign_recipients'] = $this->rows('SELECT id, campaign_id, contact_id, phone, name, status, sent_at, replied_at
            FROM talk_campaign_recipients WHERE phone IN (' . $marks . ')' . ($contactIds !== [] ? ' OR contact_id IN (' . implode(',', $contactIds) . ')' : '') . ' ORDER BY id', $phones);
        if ($contactIds === []) {
            return $out;
        }
        $cin = implode(',', $contactIds);
        $out['talk_conversations'] = $this->rows("SELECT id, protocol, contact_id, status, subject, helpdesk_ticket_id, opened_at, closed_at, last_message_at
            FROM talk_conversations WHERE contact_id IN ({$cin}) ORDER BY id");
        $out['talk_messages'] = $this->rows("SELECT m.id, m.conversation_id, m.direction, m.type, m.body, m.media_name, m.created_at
            FROM talk_messages m JOIN talk_conversations c ON c.id = m.conversation_id WHERE c.contact_id IN ({$cin}) ORDER BY m.id");
        return $out;
    }

    /**
     * Conta de acesso sem senha, 2FA ou tokens. Só aparece com vínculo no escopo (cadastro com user_id) ou acesso a todos.
     *
     * @param list<array<string, mixed>> $residents
     * @return array<string, mixed>|null
     */
    private function account(?string $document, ?string $email, array $residents, bool $globalAccess): ?array
    {
        $linked = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['user_id'] ?? 0), $residents))));
        $where = [];
        $params = [];
        if ($linked !== []) {
            $where[] = 'id IN (' . implode(',', $linked) . ')';
        }
        if ($globalAccess && $email !== null) {
            $where[] = 'LOWER(email) = ?';
            $params[] = $email;
        }
        if ($globalAccess && $document !== null) {
            $where[] = 'document IN (?, ?)';
            $params[] = $document;
            $params[] = BrDocument::format($document);
        }
        if ($where === []) {
            return null;
        }
        $rows = $this->rows('SELECT id, first_name, last_name, email, document, status, created_at, updated_at FROM users WHERE ' . implode(' OR ', $where) . ' ORDER BY id', $params);
        return $rows['rows'] === [] ? null : ['rows' => $rows['rows'], 'truncated' => $rows['truncated']];
    }

    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $name) ?? ''));
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        return $ascii !== false ? preg_replace('~[^a-z0-9 ]~', '', $ascii) ?? $name : $name;
    }

    /**
     * @param list<mixed> $params
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql . ' LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
    }
}
