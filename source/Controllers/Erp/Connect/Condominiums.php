<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Access;

/**
 * ERP v1 — módulo Condomínios (somente leitura).
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
        redirect('/erp/condominios');
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

    /**
     * Sessão autenticada (2FA concluído: Auth só grava authUser após o segundo fator),
     * erp.access e a permissão da ação. Permissões erp.* negam sem registro (ADR 0006).
     */
    private function authorize(string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect('/erp/login');
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
