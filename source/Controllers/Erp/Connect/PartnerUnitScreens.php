<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;

/**
 * ERP v1 — Unidades da imobiliária (Condomínios › Unidades da imobiliária, CN-012, ADR 0684).
 *
 * GET /condominios/imobiliaria  só leitura: as unidades que a imobiliária administra em cada condomínio ligado, com a
 * situação de pagamento (em dia ou em atraso, totais e datas) e os moradores com contato mascarado. Pelo mesmo
 * PartnerAccess::view da API /api/v1/partner/view. Permissões: erp.access + erp.partner.view.
 */
final class PartnerUnitScreens extends Controller
{
    public const PAGE_ID = 'CN-012';
    public const ROUTE = '/condominios/imobiliaria';
    public const PERMISSION = 'erp.partner.view';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    /** Conta só de imobiliária: tem a consulta do parceiro e nenhuma tela de condomínios da equipe (regra do menu). */
    public static function partnerOnly(User $user): bool
    {
        return $user->can(self::PERMISSION) && !$user->can('erp.condominiums.view');
    }

    public function index(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        $panel = new Panel();
        if (!Access::can('erp.access', $user) || !Access::can(self::PERMISSION, $user)) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', $panel->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'withNavigation' => true,
                'stateMessage' => 'Esta tela é das imobiliárias parceiras.',
            ], 'Acesso negado'));
            return;
        }
        echo $this->view->render('pages/condominiums/partner-units', $panel->layoutData($user, self::PAGE_ID, $this->data($user)));
    }

    /** @return array{partner: ?list<array>, partnerError: ?string, partnerAsOf: string} */
    public function data(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $data = ['partner' => null, 'partnerError' => null, 'partnerAsOf' => $today];
        if (Connect::databaseDisabled()) {
            return $data;
        }
        try {
            $context = CondominiumContext::id();
            $condos = array_values(array_filter((new CondominiumScope())->allowed($user), static fn (array $c): bool => $context === null || $c['id'] === $context));
            $service = new PartnerAccess();
            $rows = [];
            foreach ($condos as $c) {
                $rows[] = ['id' => $c['id'], 'name' => $c['name']] + $service->view($user, $c['id'], $today);
            }
            $data['partner'] = $rows;
        } catch (RegistryNotFound) {
            $data['partnerError'] = 'A sua imobiliária está desativada ou a conta não está ligada a ela. Fale com a Connect.';
            $data['partner'] = [];
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'partner_units_failed']);
        }
        return $data;
    }
}
