<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Proposals\ProposalComposer;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Message;

/**
 * ERP v1 — Propostas (Comunicação › Comercial, CN-011, ADR 0644 e 0650).
 *
 * GET  /comunicacao/propostas[/p/{page}]?busca=&situacao=  lista com busca, situação e quadros do topo
 * GET  /comunicacao/propostas/nova                         cadastrar uma proposta pedida por telefone, WhatsApp...
 * POST /comunicacao/propostas/nova                         grava (CSRF) e abre a proposta
 * GET  /comunicacao/propostas/{id}[?modelo=]               pedido, acompanhamento, gerar/enviar o PDF e as versões
 * POST /comunicacao/propostas/{id}                         muda situação e responsável (CSRF, padrão PRG)
 * POST /comunicacao/propostas/{id}/resposta                gera o PDF (action=generate) ou gera e envia (action=send)
 * GET  /comunicacao/propostas/{id}/pdf/{response}          abre o PDF de uma versão
 *
 * Desde a ADR 0650 criar, gerar e enviar ficam só aqui (ProposalComposer); o Studio só consulta.
 * A situação e o responsável seguem pelo ProposalDesk, que também serve a API /api/v1/proposals.
 * Permissões: erp.access + proposals.manage (a mesma de quem recebe o aviso no sininho).
 */
final class ProposalScreens extends Controller
{
    public const PAGE_ID = 'CN-011';
    public const ROUTE = '/comunicacao/propostas';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function index(array $data = []): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $search = is_string($_GET['busca'] ?? null) ? mb_substr(trim($_GET['busca']), 0, 120) : '';
        $status = is_string($_GET['situacao'] ?? null) && isset(ProposalDesk::STATUSES[$_GET['situacao']]) ? $_GET['situacao'] : '';
        $page = max(1, (int)(filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $view = ['proposalSearch' => $search, 'proposalStatus' => $status, 'proposalList' => null, 'proposalStats' => null, 'proposalError' => null, 'flash' => (new Session())->flash()];
        if (Connect::databaseDisabled()) {
            $view['proposalError'] = 'Sem conexão com o banco de dados, as propostas não podem ser listadas.';
        } else {
            try {
                $desk = new ProposalDesk();
                $view['proposalStats'] = $desk->stats();
                $view['proposalList'] = $desk->list($search, $status, $page);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'proposal_screen_failed']);
                $view['proposalError'] = 'Não foi possível carregar as propostas agora. Tente de novo em instantes.';
            }
        }
        $this->render($user, 'pages/communication/proposals', $view);
    }

    public function create(array $data = []): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $this->render($user, 'pages/communication/proposal-new', ['proposalForm' => ['profile' => 'sindico', 'origin' => 'telefone'], 'proposalErrors' => []]);
    }

    public function store(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect(erp_url(self::ROUTE . '/nova'));
            return;
        }
        $form = array_intersect_key($data, array_flip(['name', 'email', 'whatsapp', 'condominium', 'units', 'profile', 'message', 'origin', 'requested']));
        try {
            $proposal = (new ProposalComposer())->create($form, $user);
            (new Message())->success('Proposta ' . $proposal['protocol'] . ' cadastrada. Agora prepare o PDF e envie.')->flash();
            redirect(erp_url(self::ROUTE . '/' . (int)$proposal['id']));
            return;
        } catch (ProposalDeskError $error) {
            $errors = $error->fields !== [] ? $error->fields : ['form' => $error->getMessage()];
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'proposal_create_failed']);
            $errors = ['form' => 'Não foi possível gravar agora. Tente de novo em instantes.'];
        }
        http_response_code(422);
        $this->render($user, 'pages/communication/proposal-new', ['proposalForm' => array_map(static fn ($v) => is_string($v) ? $v : '', $form), 'proposalErrors' => $errors]);
    }

    public function show(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $template = is_string($_GET['modelo'] ?? null) ? $_GET['modelo'] : 'syndic';
        $this->detail($user, $this->id($data), null, [], $template);
    }

    public function respond(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        $back = self::ROUTE . '/' . $id;
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect(erp_url($back));
            return;
        }
        $send = ($data['action'] ?? '') === 'send';
        $form = array_map(static fn ($v) => is_string($v) ? $v : '', array_intersect_key($data, array_flip(['template_type', 'subject', 'introduction', 'scope', 'commercial_terms', 'payment_terms', 'notes', 'valid_until'])));
        try {
            $result = (new ProposalComposer())->respond($id, $form, $user, $send);
            $message = new Message();
            if (!$send) {
                $message->success('PDF gerado. Abra a versão abaixo para conferir antes de enviar.');
            } elseif ($result['queued']) {
                $message->success('Proposta gerada e enviada por e-mail. O e-mail sai pela fila de avisos em instantes.');
            } else {
                $message->warning('O PDF foi gerado, mas o e-mail não entrou na fila. Confira a configuração de e-mail e tente de novo.');
            }
            $message->flash();
            redirect(erp_url($back . '#prop-responses'));
            return;
        } catch (ProposalDeskError $error) {
            if ($error->status === 404) {
                (new Message())->warning('Proposta não encontrada.')->flash();
                redirect(erp_url(self::ROUTE));
                return;
            }
            $errors = $error->fields !== [] ? $error->fields : ['form' => $error->getMessage()];
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'proposal_respond_failed', 'proposal_id' => $id]);
            $errors = ['form' => 'Não foi possível gerar a proposta agora. Tente de novo em instantes.'];
        }
        http_response_code(422);
        $this->detail($user, $id, $form, $errors, (string)($form['template_type'] ?? 'syndic'));
    }

    public function pdf(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        $response = (int)(filter_var($data['response'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $file = $id > 0 && $response > 0 ? (new ProposalComposer())->pdfFile($id, $response) : null;
        if ($file === null) {
            (new Message())->warning('PDF não encontrado.')->flash();
            redirect(erp_url(self::ROUTE . ($id > 0 ? '/' . $id : '')));
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="proposta-' . $id . '-v' . $response . '.pdf"');
        header('Cache-Control: private, no-store'); // ADR 0391: nome, e-mail e telefone do interessado
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /**
     * @param ?array<string,string> $form textos digitados (quando a validação falhou) ou null para os do modelo
     * @param array<string,string> $errors
     */
    private function detail(User $user, int $id, ?array $form, array $errors, string $template): void
    {
        try {
            $desk = new ProposalDesk();
            $proposal = $desk->find($id);
            if ($proposal === null) {
                (new Message())->warning('Proposta não encontrada.')->flash();
                redirect(erp_url(self::ROUTE));
                return;
            }
            $defaults = ProposalComposer::defaults($proposal, $template);
            $this->render($user, 'pages/communication/proposal', [
                'proposal' => $proposal, 'proposalTeam' => $desk->team(), 'proposalResponses' => $desk->responses($id),
                'proposalReply' => $form === null ? $defaults : array_merge($defaults, $form), 'proposalErrors' => $errors,
                'flash' => (new Session())->flash(),
            ]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'proposal_screen_failed', 'proposal_id' => $id]);
            (new Message())->error('Não foi possível abrir a proposta agora.')->flash();
            redirect(erp_url(self::ROUTE));
        }
    }

    public function update(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        $back = self::ROUTE . '/' . $id;
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect(erp_url($back));
            return;
        }
        $assigned = filter_var($data['assigned_to'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        try {
            $proposal = (new ProposalDesk())->update($id, is_string($data['status'] ?? null) ? $data['status'] : '', $assigned);
            (new Message())->success('Acompanhamento gravado: ' . $proposal['status_label'] . '.')->flash();
        } catch (ProposalDeskError $error) {
            (new Message())->warning($error->fields !== [] ? implode(' ', $error->fields) : $error->getMessage())->flash();
            if ($error->status === 404) {
                $back = self::ROUTE;
            }
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'proposal_update_failed', 'proposal_id' => $id]);
            (new Message())->error('Não foi possível gravar agora. Tente de novo em instantes.')->flash();
        }
        redirect(erp_url($back));
    }

    /** @param array<string, mixed> $view */
    private function render(User $user, string $template, array $view): void
    {
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render($template, (new Panel())->layoutData($user, self::PAGE_ID, $view));
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }

    private function guard(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !ProposalDesk::allowed($user)) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'withNavigation' => true,
                'stateMessage' => 'Você não tem permissão para ver as propostas.',
            ], 'Acesso negado'));
            return null;
        }
        return $user;
    }
}
