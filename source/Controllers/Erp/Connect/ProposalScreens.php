<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Message;

/**
 * ERP v1 — Propostas do site (Comunicação › Comercial, CN-011, ADR 0644).
 *
 * GET  /comunicacao/propostas[/p/{page}]?busca=&situacao=  lista com busca, situação e quadros do topo
 * GET  /comunicacao/propostas/{id}                         dados do pedido, acompanhamento e PDFs gerados
 * POST /comunicacao/propostas/{id}                         muda situação e responsável (CSRF, padrão PRG)
 *
 * A mesma tabela e as mesmas regras do Studio > Gestão > Propostas (ADR 0639), pelo ProposalDesk, que também
 * serve a API /api/v1/proposals. O PDF da resposta comercial continua sendo gerado no Studio.
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

    public function show(array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $desk = new ProposalDesk();
            $proposal = $desk->find($id);
            if ($proposal === null) {
                (new Message())->warning('Proposta não encontrada.')->flash();
                redirect(erp_url(self::ROUTE));
                return;
            }
            $this->render($user, 'pages/communication/proposal', [
                'proposal' => $proposal, 'proposalTeam' => $desk->team(), 'proposalResponses' => $desk->responses($id),
                'studioUrl' => url('/studio/proposals/' . $id), 'flash' => (new Session())->flash(),
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
                'stateMessage' => 'Você não tem permissão para ver as propostas do site.',
            ], 'Acesso negado'));
            return null;
        }
        return $user;
    }
}
