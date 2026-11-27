<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\SacDesk as SacBalloon;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Atendimento do SAC pela API, para o app do SAC no celular (ADR 0660). As mesmas regras da tela
 * /operacional/chamados do ERP: erp.access e o escopo de condomínio para ver; support.manage para atender
 * (triagem, resposta, nota interna, assumir). Sessão com CSRF ou o token Bearer da equipe (ADR 0660).
 *  GET  /api/v1/sac/summary?condominium_id=    números da fila de quem entrou (meus, sem responsável, vencidos, hoje)
 *  GET  /api/v1/sac/options                     situações, prioridades, equipes, responsáveis e respostas rápidas
 *  GET  /api/v1/sac/tickets?view=mine|unassigned|overdue|today|all&status=&q=&condominium_id=&limit=&before=
 *  GET  /api/v1/sac/tickets/{id}                chamado, conversa (com notas internas para quem atende), histórico e medições
 *  POST /api/v1/sac/tickets/{id}/take           assume o chamado (responsável = eu; aberto passa a "em atendimento")
 *  POST /api/v1/sac/tickets/{id}/triage         {status?, priority?, assigned_to?, team?, category?, tags?}
 *  POST /api/v1/sac/tickets/{id}/answers        {message, internal?, resolve?, time_spent?}; multipart com attachments[] (ADR 0664)
 *  GET  /api/v1/sac/presence                    no balão do site: autorizou aparecer, no horário, quem está online (ADR 0666)
 *  POST /api/v1/sac/presence                    {public: bool} autoriza ou retira o próprio nome e foto do balão
 *  GET  /api/v1/sac/units?condominium_id=       unidades ativas do condomínio (escopo de quem entrou)
 *  POST /api/v1/sac/tickets                     abre chamado recebido por telefone, WhatsApp ou presencial
 *        {condominium_id, unit_id?, requester_name, requester_phone?, requester_email?, channel, subject, message, priority?, assign_me?}
 */
final class SacDesk
{
    public const VIEWS = ['mine', 'unassigned', 'overdue', 'today', 'all'];

    /** Como o chamado chegou quando a equipe abre pelo app; fica nas tags como "canal:<valor>". */
    public const CHANNELS = ['telefone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'presencial' => 'Presencial'];

    public function summary(): void
    {
        $this->run(false, function (User $user): array {
            // Com a fila aberta no celular, quem atende conta como online no balão do site (ADR 0666).
            (new SacBalloon())->touch($user);
            $desk = new TicketDesk();
            $ids = array_column((new CondominiumScope())->allowed($user), 'id');
            // Filtro de condomínio da Fila (ADR 0670): os números acompanham o condomínio escolhido.
            $condominium = (int)($_GET['condominium_id'] ?? 0);
            if ($condominium > 0) {
                if (!(new CondominiumScope())->canAccess($user, $condominium)) {
                    throw new TicketError('condominium_id', 'Condomínio indisponível para a sua conta.', 403);
                }
                $ids = [$condominium];
            }
            $all = $desk->metrics(array_map('intval', $ids), (int)$user->id);
            $mine = (new TicketService())->listFor($user, ['assigned' => (string)(int)$user->id, 'condominium_id' => $condominium], 200);
            $openMine = array_values(array_filter($mine, static fn (array $t): bool => !in_array($t['status'], ['resolved', 'closed'], true)));
            return [200, ['data' => [
                'mine_open' => count($openMine),
                'mine_overdue' => count(array_filter($openMine, static fn (array $t): bool => self::late($t))),
                'unassigned' => $all['unassigned'],
                'overdue' => $all['overdue'],
                'due_today' => $all['due_today'],
                'open_total' => $all['open_total'],
                'by_status' => $all['by_status'],
                'can_attend' => Access::can('support.manage', $user),
            ]]];
        });
    }

    public function options(): void
    {
        $this->run(false, function (User $user): array {
            $attend = Access::can('support.manage', $user);
            $desk = new TicketDesk();
            $pairs = static fn (array $labels): array => array_map(static fn ($k, $v): array => ['value' => $k, 'label' => $v], array_keys($labels), array_values($labels));
            return [200, ['data' => [
                'statuses' => $pairs(TicketService::STATUS_LABELS),
                'priorities' => $pairs(TicketService::PRIORITY_LABELS),
                'teams' => TicketService::TEAMS,
                'agents' => $attend ? $desk->agents() : [],
                'templates' => $attend ? $desk->templates() : [],
                'condominiums' => array_map(static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], (new CondominiumScope())->allowed($user)),
                'channels' => $pairs(self::CHANNELS),
            ]]];
        });
    }

    public function index(): void
    {
        $this->run(false, function (User $user): array {
            $view = in_array($_GET['view'] ?? '', self::VIEWS, true) ? (string)$_GET['view'] : 'mine';
            $filters = [
                'condominium_id' => (int)($_GET['condominium_id'] ?? 0),
                'status' => (string)($_GET['status'] ?? ''),
                'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80),
                // Na fila de trabalho só o que está em aberto; o histórico fica em view=all.
                'open' => ($_GET['status'] ?? '') === '' && in_array($view, ['mine', 'unassigned'], true),
                'before' => (int)($_GET['before'] ?? 0),
            ] + self::viewFilters($view, (int)$user->id);
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
            $tickets = (new TicketService())->listFor($user, $filters, $limit);
            // Carregar mais (ADR 0672): next é o id para pedir a página seguinte em before; null quando acabou.
            $next = count($tickets) === $limit ? (int)end($tickets)['id'] : null;
            return [200, ['data' => array_map(static fn (array $t): array => $t + ['late' => self::late($t)], $tickets), 'meta' => ['view' => $view, 'next' => $next]]];
        });
    }

    public function presence(): void
    {
        $this->run(true, function (User $user): array {
            $balloon = new SacBalloon();
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $body = $this->body();
                if (!array_key_exists('public', $body) || !is_bool($body['public'])) {
                    throw new TicketError('public', 'Informe public: true ou false.');
                }
                $balloon->setPublic($user, $body['public']);
            }
            $balloon->touch($user);
            return [200, ['data' => self::presenceOf($balloon, $user)]];
        });
    }

    /** @return array{public:bool, open:bool, hours:string, shown:bool, online:list<string>} */
    private static function presenceOf(SacBalloon $balloon, User $user): array
    {
        $status = $balloon->status();
        $public = $balloon->isPublic($user);
        return [
            'public' => $public,
            'open' => $status['open'],
            'hours' => $status['hours'],
            // Aparece agora no balão: as mesmas regras de SacDesk::status para esta pessoa (acabou de ser marcada ativa).
            'shown' => $status['open'] && $balloon->isShown($user),
            'online' => array_column($status['agents'], 'name'),
        ];
    }

    public function units(): void
    {
        $this->run(false, function (User $user): array {
            $condominium = (int)($_GET['condominium_id'] ?? 0);
            if ($condominium <= 0 || !(new CondominiumScope())->canAccess($user, $condominium)) {
                throw new TicketError('condominium_id', 'Condomínio indisponível para a sua conta.', 403);
            }
            return [200, ['data' => (new TicketService())->units($condominium)]];
        });
    }

    /** Chamado aberto pela equipe com quem ligou, mandou mensagem ou veio ao escritório (protocolo SAC, ADR 0637). */
    public function store(): void
    {
        $this->run(true, function (User $user): array {
            [$body, $files] = $this->input();
            $channel = (string)($body['channel'] ?? '');
            if (!isset(self::CHANNELS[$channel])) {
                throw new TicketError('channel', 'Escolha como o chamado chegou: telefone, WhatsApp ou presencial.');
            }
            if ((int)($body['condominium_id'] ?? 0) <= 0) {
                throw new TicketError('condominium_id', 'Escolha o condomínio.');
            }
            if (trim((string)($body['requester_name'] ?? '')) === '') {
                throw new TicketError('requester_name', 'Informe o nome de quem pediu.');
            }
            $input = array_intersect_key($body, array_flip(['condominium_id', 'unit_id', 'requester_name', 'requester_phone', 'requester_email', 'subject', 'message', 'priority']));
            $input = array_map(static fn ($v) => is_scalar($v) ? $v : '', $input);
            if (self::flag($body['assign_me'] ?? true)) {
                $input['assigned_to'] = (int)$user->id;
            }
            $service = new TicketService();
            // Fotos da abertura entram na mesma transação: anexo inválido = nenhum chamado criado.
            $opened = $service->open($input, 'helpdesk', $user, $files === null ? null
                : static fn (\PDO $pdo, int $ticketId) => (new TicketDesk($pdo))->attach($ticketId, null, (int)$user->id, $files));
            (new TicketDesk())->update($user, $opened['id'], ['tags' => 'canal:' . $channel]);
            return [201, ['data' => ['id' => $opened['id'], 'protocol' => $opened['protocol'], 'due_at' => $opened['due_at'],
                'ticket' => $service->findFor($user, $opened['id'])]]];
        });
    }

    public function show(array $data): void
    {
        $this->run(false, function (User $user) use ($data): array {
            $service = new TicketService();
            $ticket = $service->findFor($user, (int)($data['id'] ?? 0));
            if ($ticket === null) {
                throw new TicketError('ticket', 'Chamado não encontrado.', 404);
            }
            $attend = Access::can('support.manage', $user);
            $desk = new TicketDesk();
            return [200, ['data' => [
                'ticket' => $ticket + ['late' => self::late($ticket)],
                'messages' => array_map(static fn (array $m): array => array_diff_key($m, ['message_html' => true]), $service->conversation($ticket, $attend)),
                'opening_attachments' => self::openingAttachments((int)$ticket['id']),
                'facts' => $desk->facts((int)$ticket['id']),
                'events' => $attend ? $desk->events((int)$ticket['id']) : [],
                'can_attend' => $attend,
            ]]];
        });
    }

    public function take(array $data): void
    {
        $this->run(true, function (User $user) use ($data): array {
            $id = $this->visible($user, $data);
            $ticket = (new TicketService())->findFor($user, $id);
            $input = ['assigned_to' => (int)$user->id];
            if (($ticket['status'] ?? '') === 'open') {
                $input['status'] = 'in_progress';
            }
            $result = (new TicketDesk())->update($user, $id, $input);
            return [200, ['data' => $result + ['ticket' => (new TicketService())->findFor($user, $id)]]];
        });
    }

    public function triage(array $data): void
    {
        $this->run(true, function (User $user) use ($data): array {
            $id = $this->visible($user, $data);
            $input = array_intersect_key($this->body(), array_flip(TicketDesk::FIELDS));
            $result = (new TicketDesk())->update($user, $id, $input);
            return [200, ['data' => $result + ['ticket' => (new TicketService())->findFor($user, $id)]]];
        });
    }

    public function answer(array $data): void
    {
        $this->run(true, function (User $user) use ($data): array {
            $id = $this->visible($user, $data);
            [$body, $files] = $this->input();
            $result = (new TicketDesk())->reply($user, $id, (string)($body['message'] ?? ''), self::flag($body['internal'] ?? false),
                max(0, min(86400, (int)($body['time_spent'] ?? 0))), self::flag($body['resolve'] ?? false), $files);
            return [201, ['data' => $result + ['ticket' => (new TicketService())->findFor($user, $id)]]];
        });
    }

    /** Filtros de cada aba do app (regra pura). @return array<string,string> */
    public static function viewFilters(string $view, int $userId): array
    {
        return match ($view) {
            'mine' => ['assigned' => (string)$userId],
            'unassigned' => ['assigned' => 'unassigned'],
            'overdue' => ['due' => 'overdue'],
            'today' => ['due' => 'today'],
            default => [],
        };
    }

    /** Em aberto e com o prazo vencido (regra pura). */
    public static function late(array $ticket, ?int $now = null): bool
    {
        $due = (string)($ticket['due_at'] ?? '');
        return $due !== '' && TicketDesk::slaState((string)($ticket['status'] ?? ''), $due, $now) === 'late';
    }

    private function visible(User $user, array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        if ((new TicketService())->findFor($user, $id) === null) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        return $id;
    }

    /** @param callable(User):array{0:int,1:array<string,mixed>} $action */
    private function run(bool $attend, callable $action): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem acesso ao ERP.']]);
            return;
        }
        if ($attend && !Access::can('support.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Só a equipe de atendimento faz a triagem, responde e assume chamados.']]);
            return;
        }
        try {
            [$status, $payload] = $action($user);
            JsonResponse::send($status, $payload);
        } catch (TicketError $error) {
            $code = match ($error->status()) { 404 => 'not_found', 403 => 'forbidden', default => 'validation_failed' };
            JsonResponse::send($error->status(), ['error' => ['code' => $code, 'message' => $error->getMessage(), 'fields' => [$error->field => $error->getMessage()]]]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'sac_api_failed', 'users_id' => (int)$user->id]);
            JsonResponse::send(500, ['error' => ['code' => 'internal_error', 'message' => 'Não foi possível concluir agora. Tente de novo.']]);
        }
    }

    /** "true", "1", "on" do formulário multipart ou true do JSON. */
    public static function flag(mixed $value): bool
    {
        return $value === true || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'on', 'sim'], true)) || $value === 1;
    }

    /**
     * Corpo da requisição: multipart/form-data (com fotos em attachments[]) ou JSON.
     *
     * @return array{0:array<string,mixed>, 1:?array<string,mixed>}
     */
    private function input(): array
    {
        $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($type, 'multipart/form-data') || isset($_FILES['attachments'])) {
            $files = $_FILES['attachments'] ?? null;
            return [$_POST, is_array($files) ? $files : null];
        }
        return [$this->body(), null];
    }

    /** @return list<array{name:string,url:string}> anexos enviados na abertura do chamado (sem mensagem) */
    private static function openingAttachments(int $ticketId): array
    {
        $stmt = \Source\Core\Connect::getInstance()->prepare('SELECT id, original_name FROM studio_support_ticket_attachments WHERE ticket_id = ? AND message_id IS NULL ORDER BY id');
        $stmt->execute([$ticketId]);
        return array_map(static fn (array $r): array => ['name' => (string)$r['original_name'], 'url' => \Source\Services\Support\TicketAttachmentAccess::url((int)$r['id'])],
            $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            throw new TicketError('message', 'Mensagem grande demais.');
        }
        if (trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }
}
