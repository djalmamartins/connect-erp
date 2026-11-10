<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumRegistry;
use Source\Services\CondominiumScope;
use Source\Services\CondominiumValidator;
use Source\Services\Erp\CondominiumSetup;
use Source\Services\Erp\PanelSummaries;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;

/**
 * ERP v1 — módulo Condomínios: consulta e cadastro/edição (AP-004, ADR 0011).
 *
 * Fluxo: rota MovesCode → este controller → CondominiumScope → MariaDB. É o mesmo
 * serviço usado por /api/v1/condominiums. Não depende do controller legado Erp
 * (sessão authCondo, AppWallet), que segue congelado (C11).
 */
final class Condominiums extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function root(): void
    {
        redirect(erp_url('/condominios'));
    }

    /**
     * Menu Condomínios (ADR 0151): com um condomínio na barra superior, abre direto a visão
     * geral dele; em "Todos os condomínios", a lista com o botão "Selecionar" em cada linha.
     */
    public function index(): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $context = CondominiumContext::current();
        if ($context !== null) {
            $this->overview($user, $context['id']);
            return;
        }
        echo $this->view->render('pages/condominiums/index', $this->layoutData($user, 'Condomínios', [
            'condominiums' => (new CondominiumScope())->listing($user),
            'condoOptionsById' => array_column(CondominiumContext::options(), null, 'id'),
        ]));
    }

    public function show(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $id = $this->routeId($data);
        try {
            (new CondominiumScope())->assertAccess($user, $id);
        } catch (CondominiumAccessDenied) {
            // 404 para não revelar condomínios fora do escopo do usuário.
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return;
        }
        // Abrir a ficha de um condomínio passa a barra superior para ele (ADR 0030).
        $follow = CondominiumContext::follow($id);
        if ($follow !== null) {
            redirect(url(erp_base() . $follow));
            return;
        }
        $this->overview($user, $id);
    }

    /** POST /condominios/{id}/guia — abre (ou retoma) o guia de cadastro no primeiro passo pendente. */
    public function guide(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $id = $this->routeId($data);
        if (!$this->guideAllowed($user, $id, $data)) {
            return;
        }
        (new Session())->set(CondominiumSetup::GUIDE_KEY, $id);
        $next = CondominiumSetup::nextPending((new CondominiumSetup())->steps($user, $id));
        redirect(url(erp_base() . ($next['route'] ?? '/condominios') . '?' . CondominiumContext::FILTER_PARAM . '=' . $id));
    }

    /** POST /condominios/{id}/guia/encerrar — fecha o guia e volta para a visão geral. */
    public function endGuide(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $id = $this->routeId($data);
        if (!$this->guideAllowed($user, $id, $data)) {
            return;
        }
        (new Session())->unset(CondominiumSetup::GUIDE_KEY);
        $this->message->success('Guia de cadastro fechado. Você pode retomá-lo pela visão geral do condomínio.')->flash();
        redirect(url(erp_base() . '/condominios?' . CondominiumContext::FILTER_PARAM . '=' . $id));
    }

    /**
     * Visão geral única do condomínio (ADR 0151 e 0154): passos do cadastro em cima, indicadores e
     * rotina do antigo "Painel do condomínio" (AP-002, PanelSummaries, o mesmo serviço de
     * GET /api/v1/panels/condominium/{id}), identificação no centro e cadastros na lateral.
     */
    private function overview(User $user, int $id): void
    {
        $condominium = (new CondominiumRegistry())->find($user, $id);
        if ($condominium === null) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return;
        }
        $condominium['id'] = (int)$condominium['id'];
        $option = CondominiumContext::option($id);
        echo $this->view->render('pages/condominiums/show', $this->layoutData($user, (string)$condominium['name'], [
            'condominium' => $condominium,
            'setupSteps' => [
                'condominium' => $option ?? ['id' => $id, 'name' => (string)$condominium['name'], 'slug' => ''],
                'steps' => (new CondominiumSetup())->steps($user, $id),
                'current' => null,
            ],
        ] + $this->panelData($user, $id)));
    }

    /**
     * Indicadores do condomínio (antigo AP-002, fundido na visão geral pelo ADR 0154). Sem banco ou
     * sem erp.units.view, condoPanel fica null e a tela mostra só o cadastro; perfil sem acesso ao
     * financeiro ou ao fechamento recebe um aviso em vez dos números.
     */
    private function panelData(User $user, int $id): array
    {
        $data = ['condoPanel' => null, 'condoPanelError' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        try {
            $data['condoPanel'] = (new PanelSummaries())->condominium($user, $id);
        } catch (RegistryForbidden) {
            $data['condoPanelError'] = 'Seu perfil não tem permissão para todos os indicadores do condomínio (financeiro e fechamento).';
        } catch (RegistryNotFound) {
            $data['condoPanelError'] = 'Condomínio fora do seu escopo de acesso.';
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'condominium_panel_failed']);
            $data['condoPanelError'] = 'Não foi possível carregar os indicadores agora. Tente de novo em instantes.';
        }
        return $data;
    }

    /** GET /condominios/painel-do-condominio: a página foi fundida na visão geral (ADR 0154). */
    public function panelRedirect(): void
    {
        redirect(erp_url('/condominios'));
    }

    private function guideAllowed(User $user, int $id, array $data): bool
    {
        if (!csrf_verify($data)) {
            $this->message->warning('Sessão expirada. Tente de novo.')->flash();
            redirect(erp_url('/condominios'));
            return false;
        }
        if (!(new CondominiumScope())->canAccess($user, $id)) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return false;
        }
        return true;
    }

    /** GET /erp/condominios/novo */
    public function create(): void
    {
        $user = $this->authorize(CondominiumRegistry::MANAGE_PERMISSION);
        if ($user !== null) {
            $this->form($user, null, ['status' => 'active'], []);
        }
    }

    /** POST /erp/condominios/novo */
    public function store(array $data): void
    {
        $user = $this->authorize(CondominiumRegistry::MANAGE_PERMISSION);
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            $this->form($user, null, $data, ['_form' => 'Sessão expirada. Envie o formulário novamente.'], 419);
            return;
        }
        if (request_limit('erp_condominium_write', 30, 300)) {
            $this->form($user, null, $data, ['_form' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
            return;
        }
        $result = (new CondominiumRegistry())->create($user, $data);
        if (!$result['ok']) {
            $this->form($user, null, $data, $result['errors'], 422);
            return;
        }
        // Guia de cadastro (ADR 0151): unidades → proprietários → síndico e conselho → áreas →
        // checklist por último. O ?condominio= troca a barra superior para o condomínio novo.
        $id = (int)$result['id'];
        if (($result['data']['status'] ?? 'active') !== 'active') {
            $this->message->success('Condomínio cadastrado como inativo. Ative-o para cadastrar unidades e pessoas.')->flash();
            redirect(url(erp_base() . '/condominios'));
            return;
        }
        (new Session())->set(CondominiumSetup::GUIDE_KEY, $id);
        $next = CondominiumSetup::nextPending((new CondominiumSetup())->steps($user, $id));
        $this->message->success('Condomínio cadastrado. Agora cadastre as unidades; o guia no topo mostra os próximos passos.')->flash();
        redirect(url(erp_base() . ($next['route'] ?? '/condominios') . '?' . CondominiumContext::FILTER_PARAM . '=' . $id));
    }

    /** GET /erp/condominios/{id}/editar */
    public function edit(array $data): void
    {
        $user = $this->authorize(CondominiumRegistry::MANAGE_PERMISSION);
        if ($user === null) {
            return;
        }
        $id = $this->routeId($data);
        $condominium = (new CondominiumRegistry())->find($user, $id);
        if ($condominium === null) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return;
        }
        $this->form($user, $id, $condominium, []);
    }

    /** POST /erp/condominios/{id}/editar */
    public function update(array $data): void
    {
        $user = $this->authorize(CondominiumRegistry::MANAGE_PERMISSION);
        if ($user === null) {
            return;
        }
        $id = $this->routeId($data);
        if (!csrf_verify($data)) {
            $this->form($user, $id, $data, ['_form' => 'Sessão expirada. Envie o formulário novamente.'], 419);
            return;
        }
        if (request_limit('erp_condominium_write', 30, 300)) {
            $this->form($user, $id, $data, ['_form' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
            return;
        }
        try {
            $result = (new CondominiumRegistry())->update($user, $id, $data);
        } catch (CondominiumAccessDenied) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return;
        }
        if (!$result['ok']) {
            $this->form($user, $id, $data, $result['errors'], 422);
            return;
        }
        $this->message->success('Condomínio atualizado com sucesso.')->flash();
        // Durante o guia de cadastro, salvar os dados leva ao próximo passo pendente.
        $session = new Session();
        if ($session->has(CondominiumSetup::GUIDE_KEY) && (int)$session->{CondominiumSetup::GUIDE_KEY} === $id) {
            $next = CondominiumSetup::nextPending((new CondominiumSetup())->steps($user, $id));
            if ($next !== null) {
                redirect(url(erp_base() . $next['route'] . '?' . CondominiumContext::FILTER_PARAM . '=' . $id));
                return;
            }
        }
        redirect(url(erp_base() . '/condominios/' . $id));
    }

    /** Formulário único de cadastro/edição; os valores são escapados na view. */
    private function form(User $user, ?int $id, array $values, array $errors, int $status = 200): void
    {
        http_response_code($status);
        $title = $id === null ? 'Novo condomínio' : 'Editar condomínio';
        echo $this->view->render('pages/condominiums/form', $this->layoutData($user, $title, [
            'formTitle' => $title,
            'action' => url($id === null ? '/erp/condominios/novo' : "/erp/condominios/{$id}/editar"),
            'cancel' => url($id === null ? '/erp/condominios' : "/erp/condominios/{$id}"),
            'values' => array_intersect_key($values, array_flip(CondominiumValidator::FIELDS)),
            'errors' => $errors,
            'states' => CondominiumValidator::STATES,
            'statuses' => CondominiumValidator::STATUSES,
        ]));
    }

    private function routeId(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }

    /**
     * Sessão autenticada (2FA concluído: Auth só grava authUser após o segundo fator),
     * erp.access e a permissão da ação. Permissões erp.* negam sem registro (ADR 0006).
     */
    private function authorize(string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can($permission, $user)) {
            $this->state($user, 403, 'Acesso negado', 'Você não tem permissão para acessar esta área do ERP.');
            return null;
        }
        return $user;
    }

    private function state(User $user, int $status, string $title, string $description): void
    {
        http_response_code($status);
        echo $this->view->render('pages/errors/state', $this->layoutData($user, $title, [
            'title' => $title, 'description' => $description,
        ]));
    }

    /** Dados da moldura única do ERP (Panel::layoutData), com o menu ativo em Condomínios. */
    private function layoutData(User $user, string $title, array $data): array
    {
        return (new Panel())->layoutData($user, 'AP-004', $data, $title);
    }
}
