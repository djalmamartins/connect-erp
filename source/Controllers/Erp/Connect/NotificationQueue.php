<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DomainException;
use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;
use Source\Support\Message;

/**
 * ERP v1 — Fila de avisos (Configurações › Aplicativo e notificações, CN-005, ADR 0055 e 0072).
 *
 * Lista os avisos da outbox no condomínio da barra superior, com situação, tentativas, modo de
 * entrega e último erro. ?aviso={id} abre a trilha de entrega, que só guarda o e-mail mascarado; o
 * endereço da caixa de teste também aparece mascarado. "Reenviar" (aviso com falha ou cancelado) é
 * POST com CSRF e padrão PRG, pelo NotificationDispatcher::retry, o mesmo da API
 * /api/v1/notifications/{id}/retry. Permissões: erp.notifications.view e erp.notifications.manage.
 */
final class NotificationQueue extends Controller
{
    public const PAGE_ID = 'CN-005';
    public const ROUTE = '/configuracoes/fila-de-avisos';
    public const STATUSES = ['pending' => 'Pendente', 'processing' => 'Processando', 'sent' => 'Enviado', 'failed' => 'Falhou', 'cancelled' => 'Cancelado'];
    public const EVENTS = ['waitlist.called' => 'Lista de espera chamada', 'invoice.issued' => 'Fatura emitida', 'bank_charge.issued' => 'Boleto emitido',
        'agreement.created' => 'Acordo criado', 'agreement.breached' => 'Acordo quebrado', 'reservation.approved' => 'Reserva aprovada',
        'announcement.published' => 'Comunicado', 'collection.reminder' => 'Aviso de cobrança', 'resident.invitation' => 'Convite de morador'];
    public const MODES = ['log' => 'Só registro (nada é enviado)', 'sandbox' => 'Caixa de teste', 'production' => 'Envio real'];

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    /** GET /configuracoes/fila-de-avisos */
    public function index(): void
    {
        $user = $this->guard('view');
        if ($user === null) {
            return;
        }
        $status = is_string($_GET['situacao'] ?? null) && isset(self::STATUSES[$_GET['situacao']]) ? $_GET['situacao'] : null;
        $selected = filter_var($_GET['aviso'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $data = ['queue' => null, 'queueStatus' => $status, 'queueMode' => 'log', 'queueDetail' => null, 'queueError' => null,
            'canRetry' => Access::can('erp.notifications.manage', $user), 'flash' => (new Session())->flash()];
        if (!Connect::databaseDisabled()) {
            try {
                $dispatcher = new NotificationDispatcher();
                $data['queueMode'] = $dispatcher->mode();
                $data['queue'] = $dispatcher->list($user, CondominiumContext::id(), ['status' => $status]);
                if ($selected !== false && $selected !== null) {
                    $data['queueDetail'] = self::maskDetail($dispatcher->find($user, (int)$selected));
                }
            } catch (RegistryNotFound) {
                $data['queueError'] = 'Aviso não encontrado no seu escopo de condomínios.';
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'notification_queue_screen_failed']);
                $data['queueError'] = 'Não foi possível carregar a fila agora. Tente de novo em instantes.';
            }
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render('pages/notifications/index', (new Panel())->layoutData($user, self::PAGE_ID, $data));
    }

    /** POST /configuracoes/fila-de-avisos/{id}/reenviar */
    public function retry(array $data): void
    {
        $user = $this->guard('manage');
        if ($user === null) {
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $back = erp_url(self::ROUTE) . '?aviso=' . $id;
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect($back);
            return;
        }
        try {
            (new NotificationDispatcher())->retry($user, $id);
            (new Message())->success('Aviso devolvido à fila. Ele sai no próximo processamento.')->flash();
        } catch (RegistryNotFound) {
            (new Message())->error('Aviso não encontrado no seu escopo de condomínios.')->flash();
        } catch (DomainException $exception) {
            (new Message())->warning($exception->getMessage())->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'notification_retry_failed']);
            (new Message())->error('Não foi possível reenviar agora. Tente de novo em instantes.')->flash();
        }
        redirect($back);
    }

    /**
     * Trilha para a tela (regra pura): o destinatário já vem mascarado do banco; o endereço efetivo
     * (caixa de teste ou, em produção, o do morador) também é mascarado aqui, nunca mostrado em claro.
     */
    public static function maskDetail(array $detail): array
    {
        $detail['deliveries'] = array_map(static function (array $d): array {
            $sentTo = (string)($d['sent_to'] ?? '');
            $d['sent_to'] = $sentTo !== '' ? NotificationDispatcher::mask($sentTo) : null;
            return $d;
        }, $detail['deliveries'] ?? []);
        return $detail;
    }

    private function guard(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.notifications.view', $user) || ($action === 'manage' && !Access::can('erp.notifications.manage', $user))) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'withNavigation' => true,
                'stateMessage' => $action === 'manage' ? 'Você pode consultar a fila, mas não reenviar avisos.' : 'Você não tem permissão para ver a fila de avisos.',
            ], 'Acesso negado'));
            return null;
        }
        return $user;
    }
}
