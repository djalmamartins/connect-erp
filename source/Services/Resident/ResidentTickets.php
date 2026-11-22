<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use DomainException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/**
 * Chamados do morador no aplicativo (ADR 0305): toda ocorrência é um chamado do Help Desk, e o morador acompanha
 * e conversa pelo chamado. Reusa o que existe, sem fila paralela:
 * - abrir = registrar a ocorrência (ResidentPortal::reportOccurrence), que nasce com o chamado (origem residents);
 * - conversa = TicketService::conversation (só mensagens públicas, nunca nota interna);
 * - responder = TicketService::replyAsRequester (resolvido reabre, como no ERP); chamado fechado não aceita resposta.
 *
 * Escopo: só os chamados que o próprio morador pediu (requester_id) no condomínio do vínculo escolhido. Chamado de
 * vizinho, ou aberto pela administração sobre a unidade, não aparece. Exige a função "occurrences" do portal.
 */
final class ResidentTickets
{
    public const PER_PAGE = 20;
    public const RESIDENT_PRIORITY = ['low' => 'baixa', 'medium' => 'media', 'high' => 'alta', 'urgent' => 'alta'];
    /**
     * Tipo escolhido pelo morador (revisão de 15/11, etapa 3), guardado como categoria do chamado no Help Desk:
     * chamado = pedido ou dúvida à administração; ocorrência = problema ou fato no condomínio. Os dois abrem
     * ocorrência + chamado (ADR 0305). Sem tipo, vale ocorrência, como era antes.
     */
    public const KINDS = ['chamado' => 'Chamado', 'ocorrencia' => 'Ocorrência'];

    private PDO $pdo;
    private TicketService $tickets;

    public function __construct(?PDO $pdo = null, ?TicketService $tickets = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->tickets = $tickets ?? new TicketService($this->pdo);
    }

    /** @return array{data:list<array<string, mixed>>, meta:array{page:int, per_page:int, total:int}} */
    public function list(User $user, array $link, int $page = 1, ?string $kind = null): array
    {
        ResidentPortal::assertFeature($link, 'occurrences');
        $page = max(1, $page);
        $params = ['u' => (int)$user->id, 'c' => (int)$link['condominium_id']];
        $where = 't.requester_id = :u AND t.condominium_id = :c';
        if ($kind === 'chamado') {
            // Pedido de CND (ADR 0637) é um pedido à administração: entra como chamado.
            $where .= " AND t.category IN ('Chamado', 'Pedido de CND')";
        } elseif ($kind === 'ocorrencia') {
            $where .= " AND (t.category IS NULL OR t.category NOT IN ('Chamado', 'Pedido de CND'))";
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM studio_support_tickets t WHERE ' . $where);
        $count->execute($params);
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE ' . $where . '
            ORDER BY COALESCE(last_message_at, t.created_at) DESC, t.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        return ['data' => array_map([self::class, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => (int)$count->fetchColumn()]];
    }

    /** O chamado com a conversa pública; a primeira mensagem é a descrição da abertura. */
    public function show(User $user, array $link, int $id): array
    {
        ResidentPortal::assertFeature($link, 'occurrences');
        $row = $this->row($user, $link, $id);
        $full = $this->tickets->findFor($user, $id);
        $messages = [['id' => 0, 'author' => 'morador', 'author_name' => trim((string)($user->first_name ?? '') . ' ' . (string)($user->last_name ?? '')),
            'body' => \Source\Support\RichText::plain((string)$row['message']), 'created_at' => (string)$row['created_at'], 'attachments' => []]];
        foreach ($full !== null ? $this->tickets->conversation($full) : [] as $message) {
            $messages[] = ['id' => (int)$message['id'], 'author' => $message['from_team'] ? 'equipe' : 'morador', 'author_name' => (string)$message['author'],
                'body' => (string)$message['message'], 'created_at' => (string)$message['created_at'], 'attachments' => $message['attachments'] ?? []];
        }
        return self::present($row) + ['messages' => $messages];
    }

    /** Abre o chamado registrando a ocorrência da unidade. @return array<string, mixed> o chamado (show) */
    public function open(User $user, array $link, array $input, ?string $today = null): array
    {
        $body = ['subject' => $input['subject'] ?? '', 'description' => $input['description'] ?? '', 'priority' => $input['priority'] ?? null,
            'occurred_on' => $input['occurred_on'] ?? ''];
        $kind = self::kind($input['kind'] ?? null);
        $occurrence = (new ResidentPortal($this->pdo))->reportOccurrence($user, $link, $body, $today);
        $stmt = $this->pdo->prepare('SELECT ticket_id FROM erp_occurrences WHERE id = ?');
        $stmt->execute([(int)$occurrence['id']]);
        $ticketId = (int)$stmt->fetchColumn();
        if ($kind === 'chamado') {
            $this->pdo->prepare('UPDATE studio_support_tickets SET category = :c WHERE id = :id')->execute(['c' => self::KINDS['chamado'], 'id' => $ticketId]);
        }
        return $this->show($user, $link, $ticketId);
    }

    /** @return array{message_id:int, status:string} */
    public function reply(User $user, array $link, int $id, string $body): array
    {
        ResidentPortal::assertFeature($link, 'occurrences');
        $row = $this->row($user, $link, $id);
        if ((string)$row['status'] === 'closed') {
            throw new ResidentTicketClosed('Este chamado foi fechado. Se precisar, abra um novo.');
        }
        try {
            return $this->tickets->replyAsRequester($user, $id, $body);
        } catch (TicketError $error) {
            if ($error->status() === 404) {
                throw new RegistryNotFound();
            }
            throw new \InvalidArgumentException($error->getMessage());
        }
    }

    /**
     * Tipo informado (regra pura): vazio vale ocorrência; outro valor é recusado.
     *
     * @throws RegistryValidationException
     */
    public static function kind(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'ocorrencia';
        }
        if (!is_string($value) || !isset(self::KINDS[$value])) {
            throw new RegistryValidationException(['kind' => 'Escolha chamado ou ocorrência.']);
        }
        return $value;
    }

    /** Linha do banco para o aplicativo (regra pura). */
    public static function present(array $row): array
    {
        $priority = (string)$row['priority'];
        return [
            'id' => (int)$row['id'],
            'number' => (string)$row['protocol'],
            'subject' => (string)$row['subject'],
            'kind' => in_array($row['category'] ?? null, [self::KINDS['chamado'], ResidentCertificates::CATEGORY], true) ? 'chamado' : 'ocorrencia',
            'status' => (string)$row['status'],
            'status_label' => TicketService::STATUS_LABELS[$row['status']] ?? (string)$row['status'],
            'priority' => self::RESIDENT_PRIORITY[$priority] ?? 'media',
            'priority_label' => TicketService::PRIORITY_LABELS[$priority] ?? $priority,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at']),
            'last_message_at' => $row['last_message_at'] !== null ? (string)$row['last_message_at'] : null,
            'occurrence_id' => $row['occurrence_id'] !== null ? (int)$row['occurrence_id'] : null,
        ];
    }

    private const SELECT = 'SELECT t.id, t.protocol, t.subject, t.message, t.category, t.status, t.priority, t.created_at, t.updated_at,
            (SELECT MAX(m.created_at) FROM studio_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_internal = 0) AS last_message_at,
            o.id AS occurrence_id
        FROM studio_support_tickets t
        LEFT JOIN erp_occurrences o ON o.ticket_id = t.id AND o.deleted_at IS NULL';

    private function row(User $user, array $link, int $id): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE t.id = :id AND t.requester_id = :u AND t.condominium_id = :c');
        $stmt->execute(['id' => $id, 'u' => (int)$user->id, 'c' => (int)$link['condominium_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RegistryNotFound();
        }
        return $row;
    }
}
