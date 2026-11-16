<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * O que o Connect Talk (WhatsApp) faz no ERP (ADR 0308), sempre como o usuário de integração do token:
 *
 * - people(): quem escreveu, pelo telefone, em erp_residents (vínculo ativo e vigente), só nos condomínios do
 *   escopo desse usuário. Devolve o mínimo (nome, vínculo, e-mail, condomínio, unidade): sem CPF nem financeiro.
 * - open(): a conversa vira chamado do Help Desk pelo TicketService (origem "talk"), idempotente pelo protocolo do
 *   Talk (external_ref). O morador informado precisa ser do condomínio e da unidade, e do telefone, da conversa.
 * - transfer(): muda a área do chamado, com evento e nota interna.
 * - close(): resolve pelo TicketDesk (mesma regra do Help Desk) com a resolução como resposta. Repetir não muda nada.
 * O Talk só mexe em chamado que ele abriu (origem "talk") e do escopo: os outros são 404.
 */
final class TalkHelpdesk
{
    public const MAX_PHONES = 6;
    public const MAX_PEOPLE = 10;
    public const SECTOR_MAX = 60;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Variantes do mesmo número (regra pura): com e sem 55, com e sem o nono dígito do celular.
     * Entrada com lixo é ignorada; no máximo MAX_PHONES números de entrada.
     *
     * @return list<string>
     */
    public static function phoneVariants(string $phones): array
    {
        $variants = [];
        foreach (array_slice(explode(',', $phones), 0, self::MAX_PHONES) as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) < 10 || strlen($digits) > 13) {
                continue;
            }
            $national = (strlen($digits) >= 12 && str_starts_with($digits, '55')) ? substr($digits, 2) : $digits;
            if (strlen($national) < 10 || strlen($national) > 11) {
                continue;
            }
            $forms = [$national];
            $ddd = substr($national, 0, 2);
            $number = substr($national, 2);
            if (strlen($number) === 9 && $number[0] === '9') {
                $forms[] = $ddd . substr($number, 1);
            } elseif (strlen($number) === 8 && in_array($number[0], ['6', '7', '8', '9'], true)) {
                $forms[] = $ddd . '9' . $number;
            }
            foreach ($forms as $form) {
                $variants[] = $form;
                $variants[] = '55' . $form;
            }
        }
        return array_values(array_unique($variants));
    }

    /** Mesmo telefone? Compara os 8 últimos dígitos (regra pura), que não mudam com 55 nem com o nono dígito. */
    public static function samePhone(?string $a, ?string $b): bool
    {
        $a = preg_replace('/\D/', '', (string)$a) ?? '';
        $b = preg_replace('/\D/', '', (string)$b) ?? '';
        return strlen($a) >= 8 && strlen($b) >= 8 && substr($a, -8) === substr($b, -8);
    }

    /** Telefone mascarado para log (regra pura): só os 4 últimos dígitos. */
    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string)$phone) ?? '';
        return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /** @return list<array{id:int, name:string, relationship:string, email:?string, condominium:array{id:int,name:string}, unit:?array{id:?int,label:string}}> */
    public function people(?User $agent, string $phones): array
    {
        $variants = self::phoneVariants($phones);
        $allowed = $this->condominiumIds($agent);
        if ($variants === [] || $allowed === []) {
            return [];
        }
        $phoneMarks = implode(',', array_fill(0, count($variants), '?'));
        $condoMarks = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.relationship, r.email, r.condominium_id, c.name AS condominium_name,
                r.unit_id, u.label AS unit_label, r.unit_label AS unit_text
            FROM erp_residents r
            JOIN condominiums c ON c.id = r.condominium_id AND c.status = 'active'
            LEFT JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
            WHERE r.phone IN ({$phoneMarks}) AND r.condominium_id IN ({$condoMarks})
              AND r.status = 'active' AND r.deleted_at IS NULL
              AND (r.starts_on IS NULL OR r.starts_on <= CURDATE()) AND (r.ends_on IS NULL OR r.ends_on >= CURDATE())
            ORDER BY r.id DESC LIMIT " . self::MAX_PEOPLE);
        $stmt->execute([...$variants, ...$allowed]);
        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unitLabel = trim((string)($row['unit_label'] ?? $row['unit_text'] ?? ''));
            $people[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'relationship' => (string)$row['relationship'],
                'email' => $row['email'] !== null && $row['email'] !== '' ? (string)$row['email'] : null,
                'condominium' => ['id' => (int)$row['condominium_id'], 'name' => (string)$row['condominium_name']],
                'unit' => $unitLabel !== '' ? ['id' => $row['unit_label'] !== null ? (int)$row['unit_id'] : null, 'label' => $unitLabel] : null,
            ];
        }
        AppLogger::log('info', 'Connect Talk consultou pessoa pelo telefone', ['event_type' => 'talk_people_lookup', 'origin' => 'talk',
            'phone' => self::maskPhone($variants[0]), 'found' => count($people), 'users_id' => $agent !== null ? (int)$agent->id : null], 'integration');
        return $people;
    }

    /**
     * Abre (ou devolve, se já existe) o chamado da conversa do Talk.
     *
     * @return array{created:bool, ticket:array<string, mixed>}
     * @throws TicketError
     */
    public function open(?User $agent, array $input): array
    {
        $ref = preg_replace('/[^0-9A-Za-z._-]/', '', mb_substr(trim((string)($input['talk_protocol'] ?? '')), 0, 40)) ?? '';
        if ($ref === '') {
            throw new TicketError('talk_protocol', 'Informe o protocolo da conversa no Talk.');
        }
        $existing = $this->findByRef($ref);
        if ($existing !== null) {
            return ['created' => false, 'ticket' => $existing];
        }

        $int = static fn (string $key): ?int => ($value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$value;
        $phone = preg_replace('/\D/', '', (string)($input['requester_phone'] ?? '')) ?: null;
        $condominiumId = $int('condominium_id');
        $unitId = $int('unit_id');
        $name = trim(strip_tags((string)($input['requester_name'] ?? '')));

        $residentId = $int('resident_id');
        if ($residentId !== null) {
            $resident = $this->resident($residentId);
            if ($resident === null || ($agent !== null && !$this->scope->canAccess($agent, (int)$resident['condominium_id']))) {
                throw new TicketError('resident_id', 'Morador não encontrado.');
            }
            if ($condominiumId !== null && $condominiumId !== (int)$resident['condominium_id']) {
                throw new TicketError('condominium_id', 'O morador não pertence a este condomínio.');
            }
            if ($unitId !== null && $unitId !== ($resident['unit_id'] !== null ? (int)$resident['unit_id'] : null)) {
                throw new TicketError('unit_id', 'O morador não pertence a esta unidade.');
            }
            if ($phone !== null && !self::samePhone($phone, (string)$resident['phone'])) {
                throw new TicketError('requester_phone', 'O telefone da conversa não é o do morador informado.');
            }
            // O cadastro do ERP é a fonte do nome e do lugar.
            $condominiumId = (int)$resident['condominium_id'];
            $unitId = $resident['unit_id'] !== null ? (int)$resident['unit_id'] : null;
            $name = (string)$resident['name'];
        }
        // Sem e-mail no chamado: quem pediu é atendido pelo WhatsApp, e o Help Desk não manda e-mail paralelo.
        if ($phone === null) {
            throw new TicketError('requester_phone', 'Informe o telefone de quem escreveu.');
        }
        if (mb_strlen($name) < 2) {
            $name = 'Contato do WhatsApp' . ($phone !== null ? ' (final ' . substr($phone, -4) . ')' : '');
        }

        $message = trim(strip_tags((string)($input['message'] ?? '')));
        if (mb_strlen($message) < 10) {
            $message = 'Conversa de WhatsApp no Connect Talk, protocolo ' . $ref . '.';
        }
        $url = trim((string)($input['conversation_url'] ?? ''));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) === 1 && strlen($url) <= 500) {
            $message .= "\n\nConversa no Connect Talk: " . $url;
        }

        $service = new TicketService($this->pdo, $this->scope);
        try {
            $opened = $service->open([
                'subject' => trim((string)($input['subject'] ?? '')) ?: 'WhatsApp ' . $ref,
                'message' => $message,
                'area' => $input['area'] ?? 'general',
                'priority' => $input['priority'] ?? 'medium',
                'category' => 'WhatsApp',
                'condominium_id' => $condominiumId,
                'unit_id' => $unitId,
                'requester_name' => $name,
                'requester_phone' => $phone,
                'external_ref' => $ref,
            ], 'talk', $agent);
        } catch (\PDOException $error) {
            // Dois POST do mesmo protocolo ao mesmo tempo: o índice único segura, e o segundo devolve o primeiro.
            $again = $error->getCode() === '23000' ? $this->findByRef($ref) : null;
            if ($again === null) {
                throw $error;
            }
            return ['created' => false, 'ticket' => $again];
        }
        return ['created' => true, 'ticket' => $this->summary((int)$opened['id'])];
    }

    /**
     * Transfere o chamado para outra área, com nota interna.
     *
     * @return array<string, mixed>
     * @throws TicketError
     */
    public function transfer(User $agent, int $ticketId, array $input): array
    {
        $ticket = $this->ticketFor($agent, $ticketId);
        $area = (string)($input['area'] ?? '');
        if (!in_array($area, TicketService::AREAS, true)) {
            throw new TicketError('area', 'Área inválida. Use ' . implode(', ', TicketService::AREAS) . '.');
        }
        $sector = mb_substr(trim(strip_tags((string)($input['sector'] ?? ''))), 0, self::SECTOR_MAX);
        $note = mb_substr(trim(strip_tags((string)($input['note'] ?? ''))), 0, 2000);
        $talkUser = filter_var($input['talk_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($area === $ticket['area'] && $note === '') {
            return $this->summary($ticketId);
        }

        $text = 'Transferido no Connect Talk para ' . ($sector !== '' ? $sector : (TicketService::AREA_LABELS[$area] ?? $area))
            . ($talkUser !== false && $talkUser !== null ? ' (atendente #' . $talkUser . ' do Talk)' : '') . '.'
            . ($note !== '' ? ' Nota: ' . $note : '');
        $this->pdo->beginTransaction();
        try {
            if ($area !== $ticket['area']) {
                $this->pdo->prepare('UPDATE studio_support_tickets SET area = ? WHERE id = ?')->execute([$area, $ticketId]);
                $this->pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, old_value, new_value) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$ticketId, (int)$agent->id, 'area', $ticket['area'], $area]);
            }
            $this->pdo->prepare('INSERT INTO studio_support_ticket_messages (ticket_id, user_id, message, is_internal) VALUES (?, ?, ?, 1)')
                ->execute([$ticketId, (int)$agent->id, $text]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'studio_support_tickets', $ticketId, ['area' => $ticket['area']], ['area' => $area, 'origin' => 'talk', 'sector' => $sector]);
        return $this->summary($ticketId);
    }

    /**
     * Encerra (resolve) o chamado com a resolução como resposta. Já resolvido ou fechado: devolve como está.
     *
     * @return array<string, mixed>
     * @throws TicketError
     */
    public function close(User $agent, int $ticketId, array $input): array
    {
        $ticket = $this->ticketFor($agent, $ticketId);
        if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
            return $this->summary($ticketId);
        }
        $resolution = mb_substr(trim(strip_tags((string)($input['resolution'] ?? ''))), 0, 5000);
        $text = $resolution !== '' ? 'Atendimento encerrado no WhatsApp: ' . $resolution : 'Atendimento encerrado no WhatsApp (Connect Talk).';
        (new TicketDesk($this->pdo))->reply($agent, $ticketId, $text, false, 0, true);
        return $this->summary($ticketId);
    }

    /** @return array{id:int, protocol:string, status:string, area:string, talk_protocol:?string, condominium_id:?int, unit_id:?int} */
    public function summary(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, protocol, status, area, external_ref, condominium_id, unit_id FROM studio_support_tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        return ['id' => (int)$row['id'], 'protocol' => (string)$row['protocol'], 'status' => (string)$row['status'], 'area' => (string)$row['area'],
            'talk_protocol' => $row['external_ref'] !== null ? (string)$row['external_ref'] : null,
            'condominium_id' => $row['condominium_id'] !== null ? (int)$row['condominium_id'] : null,
            'unit_id' => $row['unit_id'] !== null ? (int)$row['unit_id'] : null];
    }

    /** Chamado aberto pelo Talk e dentro do escopo do usuário de integração; senão 404. */
    private function ticketFor(User $agent, int $ticketId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, status, area, condominium_id FROM studio_support_tickets WHERE id = ? AND origin = 'talk'");
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['condominium_id'] !== null && !$this->scope->canAccess($agent, (int)$row['condominium_id']))) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        return ['status' => (string)$row['status'], 'area' => (string)$row['area']];
    }

    /**
     * Condomínios em que a busca vale. Com usuário (API /talk, ADR 0308), o escopo dele. Sem usuário (o Talk dentro do ERP,
     * ADR 0323, identificando quem escreveu antes de qualquer atendente), todos os condomínios ativos: o ERP é de uma
     * administradora só (ADR-002), e quem vê a conversa no Talk já é a equipe dela.
     *
     * @return list<int>
     */
    private function condominiumIds(?User $agent): array
    {
        if ($agent !== null) {
            return $this->scope->allowedIds($agent);
        }
        return array_map('intval', $this->pdo->query("SELECT id FROM condominiums WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function findByRef(string $ref): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM studio_support_tickets WHERE origin = 'talk' AND external_ref = ?");
        $stmt->execute([$ref]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->summary((int)$id);
    }

    private function resident(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, unit_id, name, email, phone FROM erp_residents
            WHERE id = ? AND status = 'active' AND deleted_at IS NULL AND (ends_on IS NULL OR ends_on >= CURDATE())");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
