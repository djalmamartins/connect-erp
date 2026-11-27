<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DomainException;
use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\ManagementFees;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Message;

/**
 * ERP v1 — Honorários (Condomínios › Honorários, CN-008, ADR 0402).
 *
 * GET  /condominios/honorarios?competencia=AAAA-MM[&editar=ID]     contratos, total mensal e nota da competência
 * POST /condominios/honorarios/{id}                                  grava o contrato do condomínio {id}
 * POST /condominios/honorarios/{id}/nota                             pede a nota da competência (exige nfse.enabled)
 * POST /condominios/honorarios/pedidos/{id}/cancelar                 cancela pedido ainda não emitido, com motivo
 * Tudo pelo ManagementFees (o mesmo serviço da API /api/v1/management-fees), com CSRF e padrão PRG.
 * Permissões: erp.access + erp.management_fees.view (+ .manage para gravar). A chave das notas fica em
 * Configurações › Financeiro (nfse.enabled) e nasce desligada.
 */
final class ManagementFeeScreens extends Controller
{
    public const PAGE_ID = 'CN-008';
    public const ROUTE = '/condominios/honorarios';
    /**
     * Condomínio aberto para editar. Não pode ser ?condominio=: esse parâmetro é o filtro global da barra
     * superior (CondominiumContext::FILTER_PARAM), que troca o contexto e some da URL antes do dispatch,
     * e o botão Cadastrar/Abrir parecia não fazer nada (relato do Djalma, 27/11/2026).
     */
    public const EDIT_PARAM = 'editar';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function index(): void
    {
        $user = $this->guard('view');
        if ($user === null) {
            return;
        }
        $competence = is_string($_GET['competencia'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['competencia']) ? $_GET['competencia'] : date('Y-m');
        $editing = filter_var($_GET[self::EDIT_PARAM] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $data = ['fees' => null, 'feesCompetence' => $competence, 'feesEditing' => null, 'feesRequests' => [], 'feesError' => null,
            'canManage' => Access::can('erp.management_fees.manage', $user), 'flash' => (new Session())->flash()];
        if (!Connect::databaseDisabled()) {
            try {
                $service = new ManagementFees();
                $data['fees'] = $service->overview($user, $competence);
                if ($editing !== false && $editing !== null) {
                    $row = array_values(array_filter($data['fees']['rows'], static fn (array $r): bool => $r['condominium_id'] === (int)$editing))[0] ?? null;
                    if ($row === null) {
                        $data['feesError'] = 'Condomínio não encontrado no seu escopo.';
                    } else {
                        $data['feesEditing'] = $row;
                        $data['feesRequests'] = $service->requests($user, (int)$editing);
                    }
                }
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'management_fees_screen_failed']);
                $data['feesError'] = 'Não foi possível carregar os honorários agora. Tente de novo em instantes.';
            }
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render('pages/condominiums/management-fees', (new Panel())->layoutData($user, self::PAGE_ID, $data));
    }

    public function save(array $data): void
    {
        $id = $this->id($data);
        $this->post($data, self::ROUTE . '?' . self::EDIT_PARAM . '=' . $id . '#contrato', function (ManagementFees $service, User $user) use ($data, $id): string {
            $input = array_intersect_key($data, array_flip(['monthly_fee', 'start_date', 'end_date', 'due_day', 'service_description', 'status', 'notes']));
            $service->save($user, $id, $input);
            return 'Honorários gravados.';
        });
    }

    public function request(array $data): void
    {
        $id = $this->id($data);
        $competence = is_string($data['competence'] ?? null) ? $data['competence'] : '';
        $this->post($data, self::ROUTE . '?competencia=' . rawurlencode($competence), function (ManagementFees $service, User $user) use ($id, $competence): string {
            $service->request($user, $id, $competence);
            return 'Nota solicitada para ' . $competence . '. O envio à Receita entra na próxima etapa do módulo.';
        });
    }

    public function cancel(array $data): void
    {
        $id = $this->id($data);
        $back = self::ROUTE . (is_string($data['back'] ?? null) && ctype_digit($data['back']) ? '?' . self::EDIT_PARAM . '=' . $data['back'] . '#pedidos' : '');
        $this->post($data, $back, function (ManagementFees $service, User $user) use ($data, $id): string {
            $service->cancel($user, $id, $data['reason'] ?? null);
            return 'Pedido de nota cancelado.';
        });
    }

    private function post(array $data, string $back, callable $action): void
    {
        $user = $this->guard('manage');
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect(erp_url($back));
            return;
        }
        try {
            (new Message())->success($action(new ManagementFees(), $user))->flash();
        } catch (RegistryValidationException $exception) {
            (new Message())->error(implode(' ', $exception->errors))->flash();
        } catch (RegistryNotFound) {
            (new Message())->error('Não encontrado no seu escopo de condomínios.')->flash();
        } catch (DomainException $exception) {
            (new Message())->warning($exception->getMessage())->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'management_fees_action_failed']);
            (new Message())->error('Não foi possível gravar agora. Tente de novo em instantes.')->flash();
        }
        redirect(erp_url($back));
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }

    private function guard(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.management_fees.view', $user) || ($action === 'manage' && !Access::can('erp.management_fees.manage', $user))) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'withNavigation' => true,
                'stateMessage' => $action === 'manage' ? 'Você pode consultar os honorários, mas não alterar.' : 'Você não tem permissão para ver os honorários.',
            ], 'Acesso negado'));
            return null;
        }
        return $user;
    }
}
