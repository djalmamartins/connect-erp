<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitMoveTerms;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;
use Source\Support\Message;

/**
 * ERP v1 — Termo de entrada e de saída (Condomínios › Unidades, AP-006, ADR 0311; modelo 2 da revisão de 15/11).
 *
 * GET  /condominios/unidades/termos?condominio=&tipo=  texto em vigor para editar (condomínio vazio = geral da Connect)
 * POST /condominios/unidades/termos                     grava o texto (CSRF); a API PUT /api/v1/unit-move-terms faz o mesmo
 * GET  /condominios/unidades/termo?unidade=&pessoa=&tipo=  termo pronto para imprimir
 * Permissões: editar = erp.access + erp.units.view + erp.units.manage; imprimir = erp.access + erp.units.view +
 * erp.residents.view (o termo mostra nome e documento). Fora do escopo = 404.
 */
final class UnitMoveTermScreen extends Controller
{
    public const ROUTE = '/condominios/unidades/termos';
    public const PRINT_ROUTE = '/condominios/unidades/termo';
    public const PAGE_ID = 'AP-006';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function edit(): void
    {
        $user = $this->guard(['erp.units.manage']);
        if ($user === null) {
            return;
        }
        $kind = isset(UnitMoveTerms::KINDS[$_GET['tipo'] ?? '']) ? (string)$_GET['tipo'] : 'entrada';
        $condominium = self::condominium($_GET['condominio'] ?? (CondominiumContext::id() ?? ''));
        $this->renderEdit($user, $condominium, $kind, null, null);
    }

    public function save(array $data): void
    {
        $user = $this->guard(['erp.units.manage']);
        if ($user === null) {
            return;
        }
        $kind = isset(UnitMoveTerms::KINDS[$data['kind'] ?? '']) ? (string)$data['kind'] : 'entrada';
        $condominium = self::condominium($data['condominium_id'] ?? '');
        $back = erp_url(self::ROUTE) . '?' . http_build_query(['condominio' => $condominium ?? 'geral', 'tipo' => $kind]);
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect($back);
            return;
        }
        try {
            (new UnitMoveTerms())->save($user, $condominium, $kind, $data['body'] ?? '');
            (new Message())->success(UnitMoveTerms::KINDS[$kind] . ($condominium === null ? ' geral' : ' do condomínio') . ' salvo.')->flash();
        } catch (RegistryNotFound) {
            (new Message())->error($condominium === null ? 'Só quem acessa todos os condomínios altera o texto geral.' : 'Condomínio fora do seu acesso.')->flash();
        } catch (RegistryValidationException $exception) {
            $this->renderEdit($user, $condominium, $kind, (string)($data['body'] ?? ''), (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.'));
            return;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'unit_move_term_save_failed']);
            (new Message())->error('Não foi possível salvar agora. Tente de novo em instantes.')->flash();
        }
        redirect($back);
    }

    public function show(): void
    {
        $user = $this->guard(['erp.residents.view']);
        if ($user === null) {
            return;
        }
        $unit = filter_var($_GET['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $person = filter_var($_GET['pessoa'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $kind = (string)($_GET['tipo'] ?? '');
        $term = null;
        $error = null;
        if ($unit === false || $unit === null || !isset(UnitMoveTerms::KINDS[$kind])) {
            $error = 'Abra o termo pelo painel da unidade.';
        } else {
            try {
                $term = (new UnitMoveTerms())->render($user, (int)$unit, $person === false ? null : $person, $kind);
            } catch (RegistryNotFound) {
                http_response_code(404);
                $error = 'Unidade não encontrada no seu acesso.';
            } catch (RegistryValidationException $exception) {
                $error = (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.');
            }
        }
        $this->headers();
        echo $this->view->render('pages/condominiums/move-term', (new Panel())->layoutData($user, self::PAGE_ID, [
            'moveTerm' => $term, 'moveTermError' => $error, 'moveTermUnit' => $unit ?: null,
        ], $term['title'] ?? 'Termo de entrada/saída'));
    }

    private function renderEdit(User $user, ?int $condominium, string $kind, ?string $draft, ?string $error): void
    {
        $terms = new UnitMoveTerms();
        $scope = new CondominiumScope();
        $global = $scope->hasGlobalAccess($user);
        if ($condominium === null && !$global) {
            $condominium = CondominiumContext::id();
        }
        try {
            $current = $condominium === null && !$global ? null : $terms->forEdit($user, $condominium, $kind);
        } catch (RegistryNotFound) {
            $current = null;
            $error ??= 'Condomínio fora do seu acesso.';
        }
        $this->headers();
        echo $this->view->render('pages/condominiums/move-terms', (new Panel())->layoutData($user, self::PAGE_ID, [
            'termCondominiums' => Connect::databaseDisabled() ? [] : (new RegistryService())->condominiums($user),
            'termCondominium' => $condominium, 'termKind' => $kind, 'termGlobal' => $global,
            'termCurrent' => $current, 'termDraft' => $draft, 'termError' => $error, 'flash' => (new Session())->flash(),
        ], 'Termo de entrada/saída'));
    }

    /** "geral" ou vazio = texto geral (null); número = condomínio. */
    private static function condominium(mixed $value): ?int
    {
        $id = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @param list<string> $extra */
    private function guard(array $extra): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        foreach (array_merge(['erp.access', 'erp.units.view'], $extra) as $permission) {
            if (!Access::can($permission, $user)) {
                http_response_code(403);
                echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                    'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'stateMessage' => 'O termo de entrada/saída exige permissão em unidades e pessoas.', 'withNavigation' => true,
                ], 'Acesso negado'));
                return null;
            }
        }
        return $user;
    }

    private function headers(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
    }
}
