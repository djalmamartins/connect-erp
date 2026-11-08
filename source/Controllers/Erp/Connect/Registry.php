<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;
use Source\Support\Message;

/**
 * ERP v1 — telas dos cadastros (grupo Cadastros), todas pelo mesmo motor:
 * rota → este controller → RegistryService → MariaDB, o mesmo serviço da API
 * /api/v1/<recurso>. Formulários enviados por POST com CSRF e padrão PRG.
 *
 * Permissões: erp.access + erp.<cadastro>.view para ler, erp.<cadastro>.manage para
 * gravar ou excluir. Registro fora do escopo de condomínios responde 404.
 */
final class Registry extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    /** GET {rota}: listagem com filtros e paginação no servidor. */
    public function index(string $slug): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'view');
        if ($user === null) {
            return;
        }
        $service = new RegistryService();
        $filters = [
            'condominium_id' => $_GET['condominio'] ?? null,
            'status' => $_GET['situacao'] ?? null,
            'filter' => $_GET['tipo'] ?? null,
            'q' => $_GET['q'] ?? null,
            'page' => $_GET['pagina'] ?? 1,
        ];
        echo $this->view->render('pages/registry/index', $this->layoutData($user, $definition, null, [
            'definition' => $definition,
            'result' => $service->list($user, $slug, $filters),
            'filters' => $filters,
            'filterField' => $service->filterField($definition),
            'condominiums' => $service->condominiums($user),
            'canManage' => Access::can($definition['permission'] . '.manage', $user),
            'flash' => (new Session())->flash(),
        ]));
    }

    /** GET {rota}/novo */
    public function create(string $slug): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'manage');
        if ($user === null) {
            return;
        }
        $this->renderForm($user, $definition, null, ['status' => 'active'], []);
    }

    /** GET {rota}/{id}: detalhe com edição (somente leitura sem a permissão manage). */
    public function edit(string $slug, array $data): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'view');
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $record = (new RegistryService())->find($user, $slug, $id);
        } catch (RegistryNotFound) {
            $this->notFound($user, $definition);
            return;
        }
        $this->renderForm($user, $definition, $id, $record, []);
    }

    /** POST {rota} (criar) e POST {rota}/{id} (alterar). */
    public function save(string $slug, array $data): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'manage');
        if ($user === null) {
            return;
        }
        $id = isset($data['id']) ? $this->id($data) : null;
        if (!csrf_verify($data)) {
            http_response_code(419);
            $this->renderForm($user, $definition, $id, $data, ['_form' => 'Sua sessão expirou. Confira os dados e envie de novo.']);
            return;
        }
        $service = new RegistryService();
        try {
            $service->save($user, $slug, $data, $id);
        } catch (RegistryNotFound) {
            $this->notFound($user, $definition);
            return;
        } catch (RegistryForbidden) {
            $this->state($user, $definition, 403, 'Alteração não permitida', 'Este registro vale para todos os condomínios e só pode ser alterado por quem acessa todos eles.');
            return;
        } catch (RegistryValidationException $exception) {
            http_response_code(422);
            $this->renderForm($user, $definition, $id, $data, $exception->errors);
            return;
        }
        $label = self::upperFirst($definition['entity']);
        (new Message())->success($id === null ? "{$label} cadastrad{$definition['gender']} com sucesso." : "{$label} atualizad{$definition['gender']} com sucesso.")->flash();
        redirect(erp_url($definition['route']));
    }

    /** POST {rota}/{id}/excluir */
    public function destroy(string $slug, array $data): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'manage');
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente excluir de novo.')->flash();
            redirect(erp_url($definition['route']));
            return;
        }
        try {
            (new RegistryService())->delete($user, $slug, $this->id($data));
        } catch (RegistryNotFound) {
            $this->notFound($user, $definition);
            return;
        } catch (RegistryForbidden) {
            $this->state($user, $definition, 403, 'Exclusão não permitida', 'Este registro vale para todos os condomínios e só pode ser excluído por quem acessa todos eles.');
            return;
        }
        (new Message())->success(self::upperFirst($definition['entity']) . " excluíd{$definition['gender']}.")->flash();
        redirect(erp_url($definition['route']));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(User $user, array $definition, ?int $id, array $values, array $errors): void
    {
        $service = new RegistryService();
        $canManage = Access::can($definition['permission'] . '.manage', $user);
        $history = [];
        if ($id !== null) {
            try {
                $history = $service->history($user, $definition['slug'], $id);
            } catch (RegistryNotFound) {
                $this->notFound($user, $definition);
                return;
            }
        }
        $article = $definition['gender'] === 'a' ? 'Nova' : 'Novo';
        $title = $id === null ? $article . ' ' . $definition['entity'] : (string)(($values[$definition['title']] ?? '') !== '' ? $values[$definition['title']] : self::upperFirst($definition['entity']));
        header('Cache-Control: no-store, private');
        echo $this->view->render('pages/registry/form', $this->layoutData($user, $definition, $title, [
            'definition' => $definition,
            'recordId' => $id,
            'values' => $values,
            'errors' => $errors,
            'history' => $history,
            'condominiums' => $service->condominiums($user),
            'canManage' => $canManage && ($id === null || $service->canWrite($user, $definition['slug'], $values)),
        ]));
    }

    private function authorize(array $definition, string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            $this->state($user, $definition, 403, 'Acesso negado', 'Sua conta não tem permissão para acessar o ERP. Fale com o administrador da Connect.');
            return null;
        }
        if (!Access::can($definition['permission'] . '.view', $user) || ($action === 'manage' && !Access::can($definition['permission'] . '.manage', $user))) {
            $this->state($user, $definition, 403, 'Acesso negado', $action === 'manage' ? 'Você pode consultar, mas não alterar este cadastro.' : 'Você não tem permissão para acessar este cadastro.');
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

    private function notFound(User $user, array $definition): void
    {
        $this->state($user, $definition, 404, 'Registro não encontrado', 'O registro não existe, foi excluído ou não está no seu escopo de condomínios.');
    }

    private function state(User $user, array $definition, int $code, string $title, string $message): void
    {
        http_response_code($code);
        echo $this->view->render('pages/system/state', $this->layoutData($user, $definition, $title, [
            'stateCode' => $code,
            'stateTitle' => $title,
            'stateMessage' => $message,
            'withNavigation' => true,
        ]));
    }

    private function layoutData(User $user, array $definition, ?string $title, array $data): array
    {
        return (new Panel())->layoutData($user, $definition['page'], $data, $title);
    }

    /** Primeira letra maiúscula com acento ("área" vira "Área"); ucfirst() não trata multibyte. */
    private static function upperFirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
