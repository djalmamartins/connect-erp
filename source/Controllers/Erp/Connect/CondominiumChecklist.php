<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\OnboardingChecklist;
use Source\Services\OnboardingItemNotFound;
use Source\Services\OnboardingValidationError;
use Source\Support\Access;

/**
 * ERP v1 — checklist de chegada do condomínio (ADR 0013).
 *
 * Leitura com erp.condominiums.view; alterações com erp.condominiums.manage e CSRF.
 * O escopo do condomínio é sempre verificado no servidor (CondominiumScope) e, fora
 * dele, a resposta é 404 para não revelar condomínios de outros usuários.
 */
final class CondominiumChecklist extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function show(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view');
        $condominium = $user ? $this->condominium($user, $data) : null;
        if ($condominium === null) {
            return;
        }
        $service = new OnboardingChecklist();
        $items = $service->items($condominium['id']);
        $canManage = Access::can('erp.condominiums.manage', $user);
        echo $this->view->render('pages/condominiums/checklist', $this->layoutData($user, "Checklist de chegada — {$condominium['name']}", [
            'condominium' => $condominium,
            'items' => $items,
            'summary' => $service->summary($condominium['id'], $items),
            'canManage' => $canManage,
            'responsibles' => $canManage ? $service->responsibleOptions() : [],
            'editing' => (int)($_GET['item'] ?? 0),
        ]));
    }

    public function start(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage');
        $condominium = $user ? $this->condominium($user, $data) : null;
        if ($condominium === null || !$this->csrf($user)) {
            return;
        }
        $created = (new OnboardingChecklist())->start($condominium['id'], (int)$user->id);
        $this->message->success($created > 0 ? "Checklist iniciado com {$created} itens." : 'O checklist já estava atualizado com o catálogo.')->flash();
        redirect($this->checklistUrl($condominium['id']));
    }

    public function update(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage');
        $condominium = $user ? $this->condominium($user, $data) : null;
        if ($condominium === null || !$this->csrf($user)) {
            return;
        }
        $itemId = (int)(filter_var($data['item'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $input = array_intersect_key($_POST, array_flip(['status', 'due_date', 'expires_at', 'notes', 'responsible_user_id']));
        try {
            $item = (new OnboardingChecklist())->update($condominium['id'], $itemId, $input, (int)$user->id);
        } catch (OnboardingItemNotFound) {
            $this->state($user, 404, 'Item não encontrado', 'O item não existe neste checklist.');
            return;
        } catch (OnboardingValidationError $error) {
            $this->message->error(implode(' ', $error->errors))->flash();
            redirect($this->checklistUrl($condominium['id']) . '?item=' . $itemId . '#item-' . $itemId);
            return;
        }
        $suffix = $item['protocol'] && in_array($item['status'], OnboardingChecklist::OPEN_STATUSES, true) ? " Protocolo {$item['protocol']}." : '';
        $this->message->success("Item “{$item['title']}” atualizado.{$suffix}")->flash();
        redirect($this->checklistUrl($condominium['id']) . '#item-' . $itemId);
    }

    public function add(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage');
        $condominium = $user ? $this->condominium($user, $data) : null;
        if ($condominium === null || !$this->csrf($user)) {
            return;
        }
        try {
            $item = (new OnboardingChecklist())->addCustom($condominium['id'], (string)($_POST['category'] ?? ''), (string)($_POST['title'] ?? ''), (int)$user->id);
        } catch (OnboardingValidationError $error) {
            $this->message->error(implode(' ', $error->errors))->flash();
            redirect($this->checklistUrl($condominium['id']) . '#novo-item');
            return;
        }
        $this->message->success("Pendência registrada com o protocolo {$item['protocol']}.")->flash();
        redirect($this->checklistUrl($condominium['id']) . '#item-' . $item['id']);
    }

    /** @return array{id:int,name:string,document:?string,status:string}|null */
    private function condominium(User $user, array $data): ?array
    {
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $scope = new CondominiumScope();
        try {
            $scope->assertAccess($user, $id);
        } catch (CondominiumAccessDenied) {
            $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
            return null;
        }
        foreach ($scope->allowed($user) as $condominium) {
            if ($condominium['id'] === $id) {
                return $condominium;
            }
        }
        $this->state($user, 404, 'Condomínio não encontrado', 'O condomínio não existe ou não está no seu escopo de acesso.');
        return null;
    }

    private function csrf(User $user): bool
    {
        if (csrf_verify($_POST)) {
            return true;
        }
        $this->state($user, 419, 'Sessão expirada', 'Atualize a página e tente novamente.');
        return false;
    }

    /** Mesma regra do módulo Condomínios: sessão com 2FA concluído, erp.access e a permissão da ação. */
    private function authorize(string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect('/studio/login');
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can($permission, $user)) {
            $this->state($user, 403, 'Acesso negado', 'Você não tem permissão para acessar esta área do ERP.');
            return null;
        }
        return $user;
    }

    private function checklistUrl(int $condominiumId): string
    {
        return url("/erp/condominios/{$condominiumId}/checklist");
    }

    private function state(User $user, int $status, string $title, string $description): void
    {
        http_response_code($status);
        echo $this->view->render('pages/errors/state', $this->layoutData($user, $title, [
            'title' => $title, 'description' => $description,
        ]));
    }

    private function layoutData(User $user, string $title, array $data): array
    {
        return array_merge([
            'head' => $this->seo->render("{$title} | Connect ERP", 'Gestão condominial Connect', url('/erp'), themeErp('/assets/images/share.jpg'), false),
            'user' => $user,
            'active' => 'condominiums',
        ], $data);
    }
}
