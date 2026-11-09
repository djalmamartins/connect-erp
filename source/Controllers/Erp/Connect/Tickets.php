<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Environments;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * ERP v1 — Chamados (Operacional › Chamados, CN-004, ADR 0034).
 *
 * Abre chamados para o Help Desk a partir do ERP, com condomínio, unidade e solicitante
 * (o próprio usuário ou um morador, por nome, e-mail e telefone), e lista os chamados dos
 * condomínios do escopo. O atendimento acontece no Help Desk; aqui só abertura e acompanhamento.
 * Mesmo serviço da API /api/v1/tickets. Formulário com CSRF e padrão PRG.
 */
final class Tickets extends Controller
{
    private const PAGE_ID = 'CN-004';
    private const BASE = '/operacional/chamados';
    private const FLASH = 'erpTicketFlash';

    private TicketService $service;

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
        $this->service = new TicketService();
    }

    /** GET /operacional/chamados — formulário de abertura e chamados do escopo. */
    public function index(): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $session = new Session();
        $flash = $session->has(self::FLASH) ? (array)$session->{self::FLASH} : null;
        $session->unset(self::FLASH);
        $old = $flash['old'] ?? [];
        $condominiums = (new CondominiumScope())->allowed($user);
        $filters = [
            'condominium_id' => (int)($_GET['condominio'] ?? 0),
            'status' => (string)($_GET['situacao'] ?? ''),
            'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80),
        ];
        $panel = new Panel();
        $layout = $panel->layoutData($user, self::PAGE_ID, [], null);
        $current = $layout['condoCurrent']['id'] ?? null;
        echo $this->view->render('pages/tickets/index', $panel->layoutData($user, self::PAGE_ID, [
            'base' => self::BASE,
            'flash' => $flash !== null ? ['type' => $flash['type'], 'text' => $flash['text'], 'protocol' => $flash['protocol'] ?? null, 'ticket_id' => $flash['ticket_id'] ?? null] : null,
            'errors' => $flash['errors'] ?? [],
            'old' => $old + ['condominium_id' => $current !== null ? (int)$current : 0],
            'condominiums' => $condominiums,
            'unitsByCondominium' => $this->service->unitsByCondominium(array_column($condominiums, 'id')),
            'tickets' => $this->service->listFor($user, $filters),
            'filters' => $filters,
            'helpdeskUrl' => Access::can('support.manage', $user) ? Environments::href('helpdesk') : null,
        ]));
    }

    /** POST /operacional/chamados — abre o chamado no Help Desk. */
    public function store(): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        if (!csrf_verify($_POST)) {
            $this->flash(['type' => 'danger', 'text' => 'Sua sessão expirou. Envie de novo.']);
            redirect(erp_url(self::BASE));
            return;
        }
        if (request_limit('erp_ticket_open', 20, 300)) {
            $this->flash(['type' => 'danger', 'text' => 'Muitos chamados em pouco tempo. Aguarde alguns minutos.']);
            redirect(erp_url(self::BASE));
            return;
        }
        $input = array_intersect_key($_POST, array_flip(['subject', 'message', 'area', 'priority', 'condominium_id', 'unit_id', 'requester_name', 'requester_email', 'requester_phone']));
        // "Eu mesmo" não leva os campos do morador, mesmo que tenham sido preenchidos.
        if (($_POST['requester'] ?? 'me') !== 'other') {
            unset($input['requester_name'], $input['requester_email'], $input['requester_phone']);
        }
        try {
            $opened = $this->service->open($input, 'erp', $user);
        } catch (TicketError $error) {
            $this->flash(['type' => 'danger', 'text' => $error->getMessage(), 'errors' => [$error->field => $error->getMessage()], 'old' => $_POST]);
            redirect(erp_url(self::BASE . '#abrir'));
            return;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_create_failed', 'users_id' => (int)$user->id, 'origin' => 'erp']);
            $this->flash(['type' => 'danger', 'text' => 'Não foi possível abrir o chamado agora. Tente novamente.', 'old' => $_POST]);
            redirect(erp_url(self::BASE . '#abrir'));
            return;
        }
        $this->flash(['type' => 'success', 'text' => "Chamado {$opened['protocol']} aberto. A equipe do Help Desk já foi avisada.", 'protocol' => $opened['protocol'], 'ticket_id' => $opened['id']]);
        redirect(erp_url(self::BASE));
    }

    /** GET /operacional/chamados/{id} — o chamado com a conversa pública e a resposta (ADR 0142). */
    public function show(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $ticket = $this->find($user, $data);
        if ($ticket === null) {
            return;
        }
        $session = new Session();
        $flash = $session->has(self::FLASH) ? (array)$session->{self::FLASH} : null;
        $session->unset(self::FLASH);
        $canAttend = Access::can('support.manage', $user);
        echo $this->view->render('pages/tickets/show', (new Panel())->layoutData($user, self::PAGE_ID, [
            'base' => self::BASE,
            'ticket' => $ticket,
            'messages' => $this->service->conversation($ticket),
            'flash' => $flash !== null ? ['type' => $flash['type'], 'text' => $flash['text']] : null,
            'errors' => $flash['errors'] ?? [],
            'old' => $flash['old'] ?? [],
            'helpdeskUrl' => $canAttend ? Environments::link('helpdesk', TicketService::helpdeskPath((int)$ticket['id'])) : null,
        ], 'Chamado ' . $ticket['protocol']));
    }

    /** POST /operacional/chamados/{id}/responder — mensagem de quem pediu para a equipe. */
    public function reply(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $id = (int)filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
        $back = erp_url(self::BASE . '/' . $id);
        if (!csrf_verify($_POST)) {
            $this->flash(['type' => 'danger', 'text' => 'Sua sessão expirou. Envie de novo.']);
            redirect($back);
            return;
        }
        if (request_limit('erp_ticket_reply', 30, 300)) {
            $this->flash(['type' => 'danger', 'text' => 'Muitas mensagens em pouco tempo. Aguarde alguns minutos.']);
            redirect($back);
            return;
        }
        try {
            $result = $this->service->replyAsRequester($user, $id, (string)($_POST['message'] ?? ''));
        } catch (TicketError $error) {
            if ($error->getCode() === 404) {
                $this->notFound($user);
                return;
            }
            $this->flash(['type' => 'danger', 'text' => $error->getMessage(), 'errors' => [$error->field => $error->getMessage()], 'old' => $_POST]);
            redirect($back . '#responder');
            return;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_reply_failed', 'users_id' => (int)$user->id, 'ticket_id' => $id]);
            $this->flash(['type' => 'danger', 'text' => 'Não foi possível enviar a mensagem agora. Tente novamente.', 'old' => $_POST]);
            redirect($back . '#responder');
            return;
        }
        $this->flash(['type' => 'success', 'text' => $result['status'] === 'open' ? 'Mensagem enviada e chamado reaberto. A equipe foi avisada.' : 'Mensagem enviada. A equipe do Help Desk foi avisada.']);
        redirect($back . '#conversa');
    }

    private function find(User $user, array $data): ?array
    {
        $id = (int)filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
        $ticket = $id > 0 ? $this->service->findFor($user, $id) : null;
        if ($ticket === null) {
            $this->notFound($user);
        }
        return $ticket;
    }

    private function notFound(User $user): void
    {
        http_response_code(404);
        echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
            'stateCode' => 404, 'stateTitle' => 'Chamado não encontrado', 'stateMessage' => 'O chamado não existe ou não é de um condomínio seu.', 'withNavigation' => true,
        ], 'Chamado não encontrado'));
    }

    private function guard(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'stateMessage' => 'Você não tem acesso ao ERP.', 'withNavigation' => true,
            ], 'Acesso negado'));
            return null;
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        return $user;
    }

    private function flash(array $flash): void
    {
        if (isset($flash['old'])) {
            $flash['old'] = array_map(static fn ($value): string => is_scalar($value) ? mb_substr((string)$value, 0, 5000) : '', array_diff_key((array)$flash['old'], ['csrf' => true]));
        }
        (new Session())->set(self::FLASH, $flash);
    }
}
