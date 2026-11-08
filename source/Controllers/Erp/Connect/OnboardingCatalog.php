<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\OnboardingCatalog as Catalog;
use Source\Services\OnboardingItemNotFound;
use Source\Services\OnboardingValidationError;
use Source\Support\Access;

/**
 * ERP v1 — catálogo do checklist de chegada (ADR 0013). Exige erp.access e
 * erp.onboarding.catalog; alterações com CSRF e registradas no Audit.
 */
final class OnboardingCatalog extends Controller
{
    private const FIELDS = ['code', 'category', 'title', 'description', 'default_due_days', 'renewal_months', 'position'];

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function index(): void
    {
        $user = $this->authorize();
        if ($user === null) {
            return;
        }
        $catalog = new Catalog();
        $editing = null;
        $id = (int)($_GET['item'] ?? 0);
        if ($id > 0) {
            try {
                $editing = $catalog->find($id);
            } catch (OnboardingItemNotFound) {
                $editing = null;
            }
        }
        echo $this->view->render('pages/checklist/catalog', [
            'head' => $this->seo->render('Catálogo do checklist de chegada | Connect ERP', 'Gestão condominial Connect', url('/erp'), themeErp('/assets/images/share.jpg'), false),
            'user' => $user, 'active' => 'condominiums',
            'templates' => $catalog->all(), 'editing' => $editing, 'creating' => isset($_GET['novo']),
        ]);
    }

    public function create(): void
    {
        $user = $this->authorize();
        if ($user === null || !$this->csrf()) {
            return;
        }
        try {
            $template = (new Catalog())->create($this->input());
        } catch (OnboardingValidationError $error) {
            $this->message->error(implode(' ', $error->errors))->flash();
            redirect(url('/erp/checklist/catalogo?novo=1#form'));
            return;
        }
        $this->message->success("Item “{$template['title']}” incluído no catálogo. Use “Atualizar com o catálogo” nos condomínios para receber o item.")->flash();
        redirect(url('/erp/checklist/catalogo#tpl-' . $template['id']));
    }

    public function update(array $data): void
    {
        $user = $this->authorize();
        if ($user === null || !$this->csrf()) {
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $input = $this->input();
        unset($input['code']);
        try {
            $template = (new Catalog())->update($id, $input);
        } catch (OnboardingItemNotFound) {
            $this->message->error('Item do catálogo não encontrado.')->flash();
            redirect(url('/erp/checklist/catalogo'));
            return;
        } catch (OnboardingValidationError $error) {
            $this->message->error(implode(' ', $error->errors))->flash();
            redirect(url("/erp/checklist/catalogo?item={$id}#form"));
            return;
        }
        $this->message->success("Item “{$template['title']}” atualizado.")->flash();
        redirect(url('/erp/checklist/catalogo#tpl-' . $template['id']));
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $input = array_intersect_key($_POST, array_flip(self::FIELDS));
        $input['active'] = isset($_POST['active']);
        return $input;
    }

    private function csrf(): bool
    {
        if (csrf_verify($_POST)) {
            return true;
        }
        $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->flash();
        redirect(url('/erp/checklist/catalogo'));
        return false;
    }

    private function authorize(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect('/studio/login');
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.onboarding.catalog', $user)) {
            http_response_code(403);
            echo $this->view->render('pages/errors/state', [
                'head' => $this->seo->render('Acesso negado | Connect ERP', 'Gestão condominial Connect', url('/erp'), themeErp('/assets/images/share.jpg'), false),
                'user' => $user, 'active' => 'condominiums',
                'title' => 'Acesso negado', 'description' => 'Você não tem permissão para configurar o catálogo do checklist.',
            ]);
            return null;
        }
        return $user;
    }
}
