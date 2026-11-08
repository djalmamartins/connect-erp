<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumRegistry;
use Source\Services\CondominiumScope;
use Source\Services\CondominiumValidator;
use Source\Support\Access;

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

    public function index(): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        echo $this->view->render('pages/condominiums/index', $this->layoutData($user, 'Condomínios', [
            'condominiums' => (new CondominiumScope())->allowed($user),
        ]));
    }

    public function show(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $scope = new CondominiumScope();
        try {
            $scope->assertAccess($user, $id);
        } catch (CondominiumAccessDenied) {
            // 404 para não revelar condomínios fora do escopo do usuário.
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return;
        }
        foreach ($scope->allowed($user) as $condominium) {
            if ($condominium['id'] === $id) {
                echo $this->view->render('pages/condominiums/show', $this->layoutData($user, $condominium['name'], [
                    'condominium' => $condominium,
                ]));
                return;
            }
        }
        $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
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
        $this->message->success('Condomínio cadastrado com sucesso.')->flash();
        redirect('/erp/condominios/' . $result['id']);
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
        redirect('/erp/condominios/' . $id);
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
