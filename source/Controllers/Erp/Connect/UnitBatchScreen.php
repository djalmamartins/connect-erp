<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * ERP v1 — Cadastro rápido de unidades (Condomínios › Unidades, AP-006, ADR 0140).
 *
 * GET /condominios/unidades/cadastro-rapido: formulário dos parâmetros (blocos, andares, unidades por
 * andar, números pulados e avulsos). Pré-visualizar e criar usam a API /api/v1/unit-batches (erp.js,
 * data-erp-unit-batch), que valida tudo de novo e só cria as que ainda não existem.
 * Permissões: erp.access + erp.units.view + erp.units.manage.
 */
final class UnitBatchScreen extends Controller
{
    public const ROUTE = '/condominios/unidades/cadastro-rapido';
    public const PAGE_ID = 'AP-006';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.units.view', $user) || !Access::can('erp.units.manage', $user)) {
            $this->state($user, 403, 'Acesso negado', 'O cadastro rápido exige permissão para alterar unidades.');
            return;
        }
        $condominiums = Connect::databaseDisabled() ? [] : (new RegistryService())->condominiums($user);
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render('pages/condominiums/unit-batch', (new Panel())->layoutData($user, self::PAGE_ID, [
            'batchCondominiums' => $condominiums,
            'batchSelected' => CondominiumContext::id(),
            'batchTypes' => \Source\Services\Erp\UnitBatch::TYPES,
        ], 'Cadastro rápido de unidades'));
    }

    private function state(User $user, int $code, string $title, string $message): void
    {
        http_response_code($code);
        echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
            'stateCode' => $code, 'stateTitle' => $title, 'stateMessage' => $message, 'withNavigation' => true,
        ], $title));
    }
}
