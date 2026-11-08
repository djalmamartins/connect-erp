<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CondominiumService;
use Source\Services\Erp\CondominiumValidationException;
use Source\Support\Access;
use Source\Support\Message;
use Source\Core\Session;

/**
 * ERP v1 — módulo Condomínios.
 *
 * Fluxo: rota MovesCode → este controller → CondominiumScope (leitura no escopo) ou
 * CondominiumService (cadastro) → MariaDB. A leitura é a mesma de /api/v1/condominiums.
 * Cadastrar e alterar exigem erp.condominiums.manage + acesso a todos os condomínios
 * (administradora única, ADR-002); formulários por POST com CSRF e padrão PRG. Não depende do controller legado Erp
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
        $canManage = $this->canManage($user);
        echo $this->view->render('pages/condominiums/index', $this->layoutData($user, 'Condomínios', [
            // Quem administra a carteira vê também os inativos, para poder reativá-los.
            'condominiums' => $canManage ? (new CondominiumService())->all() : (new CondominiumScope())->allowed($user),
            'canManage' => $canManage,
            'flash' => (new Session())->flash(),
        ]));
    }

    public function show(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        if ($this->canManage($user)) {
            $condominium = (new CondominiumService())->find($id);
            if ($condominium === null) {
                $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
                return;
            }
            echo $this->view->render('pages/condominiums/show', $this->layoutData($user, $condominium['name'], [
                'condominium' => $condominium, 'canManage' => true, 'flash' => (new Session())->flash(),
            ]));
            return;
        }
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
                    'condominium' => $condominium, 'canManage' => false, 'flash' => null,
                ]));
                return;
            }
        }
        $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
    }

    /** GET /condominios/novo */
    public function create(): void
    {
        $user = $this->authorizeManage();
        if ($user === null) {
            return;
        }
        $this->renderForm($user, null, ['status' => 'active'], []);
    }

    /** GET /condominios/{id}/editar */
    public function edit(array $data): void
    {
        $user = $this->authorizeManage();
        if ($user === null) {
            return;
        }
        $condominium = (new CondominiumService())->find($this->id($data));
        if ($condominium === null) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe.');
            return;
        }
        $this->renderForm($user, $condominium['id'], $condominium, []);
    }

    /** POST /condominios (criar) e POST /condominios/{id} (alterar). */
    public function save(array $data): void
    {
        $user = $this->authorizeManage();
        if ($user === null) {
            return;
        }
        $id = isset($data['id']) ? $this->id($data) : null;
        $values = array_intersect_key($data, ['name' => 1, 'document' => 1, 'status' => 1]);
        if (!csrf_verify($data)) {
            http_response_code(419);
            $this->renderForm($user, $id, $values, ['_form' => 'Sua sessão expirou. Confira os dados e envie de novo.']);
            return;
        }
        $service = new CondominiumService();
        if ($id !== null && $service->find($id) === null) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe.');
            return;
        }
        try {
            $savedId = $service->save($values, $id);
        } catch (CondominiumValidationException $exception) {
            http_response_code(422);
            $this->renderForm($user, $id, $values, $exception->errors);
            return;
        }
        (new Message())->success($id === null ? 'Condomínio cadastrado com sucesso.' : 'Condomínio atualizado com sucesso.')->flash();
        redirect(erp_url('/condominios/' . $savedId));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(User $user, ?int $id, array $values, array $errors): void
    {
        $title = $id === null ? 'Novo condomínio' : (string)(($values['name'] ?? '') !== '' ? $values['name'] : 'Condomínio');
        header('Cache-Control: no-store, private');
        echo $this->view->render('pages/condominiums/form', $this->layoutData($user, $title, [
            'recordId' => $id, 'values' => $values, 'errors' => $errors, 'statuses' => CondominiumService::STATUS,
        ]));
    }

    /** Cadastro de condomínio: manage + acesso global explícito (erp.condominiums.all ou developer). */
    private function canManage(User $user): bool
    {
        return Access::can('erp.condominiums.manage', $user) && (new CondominiumScope())->hasGlobalAccess($user);
    }

    private function authorizeManage(): ?User
    {
        $user = $this->authorize('erp.condominiums.view');
        if ($user === null) {
            return null;
        }
        if (!$this->canManage($user)) {
            $this->state($user, 403, 'Acesso negado', 'Somente quem administra todos os condomínios da Connect pode cadastrar ou alterar condomínios.');
            return null;
        }
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        return $user;
    }

    private function id(array $data): int
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
