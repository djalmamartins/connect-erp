<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DateTimeImmutable;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MyDay;
use Source\Services\Erp\MyDayError;
use Source\Services\Support\SacDesk;
use Source\Support\Access;

/**
 * ERP v1 — Meu Dia (CN-010, ADR 0630): agenda do dia e da semana, o que falta agendar e o painel da visita,
 * de quem está logado (ou, com erp.myday.team, de alguém da equipe). Regras em MyDay, as mesmas da API
 * /api/v1/my-day. Formulários com CSRF e padrão PRG, sem depender de JavaScript.
 */
final class MyDayScreens extends Controller
{
    public const PAGE_ID = 'CN-010';
    public const BASE = '/meu-dia';

    private MyDay $service;

    public function __construct(?MyDay $service = null)
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
        $this->service = $service ?? new MyDay();
    }

    /** GET /meu-dia?data=AAAA-MM-DD&pessoa=ID&visao=dia|semana&condominio=ID */
    public function index(): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['data'] ?? '')) ?: new DateTimeImmutable('today');
        $personId = (int)($_GET['pessoa'] ?? 0) ?: null;
        try {
            $owner = $this->service->owner($user, $personId);
            $day = $this->service->day($owner, $date);
        } catch (MyDayError $error) {
            $this->state($user, $error);
            return;
        }
        // Painel da visita: o condomínio pedido, ou o da primeira vistoria do dia (fora do acesso de quem vê: sem painel).
        $panelId = (int)($_GET['visita'] ?? 0) ?: ($day['day_visits'][0]['condominium_id'] ?? null);
        try {
            $panel = $panelId !== null ? $this->service->visitPanel($user, (int)$panelId) : null;
        } catch (MyDayError) {
            $panel = null;
        }
        $session = new Session();
        $flash = $session->has('erpMyDayFlash') ? (array)$session->erpMyDayFlash : null;
        $errors = $session->has('erpMyDayErrors') ? (array)$session->erpMyDayErrors : [];
        $old = $session->has('erpMyDayOld') ? (array)$session->erpMyDayOld : [];
        $session->unset('erpMyDayFlash');
        $session->unset('erpMyDayErrors');
        $session->unset('erpMyDayOld');
        $this->noCache();
        echo $this->view->render('pages/my-day/index', (new Panel())->layoutData($user, self::PAGE_ID, [
            'day' => $day,
            'panel' => $panel,
            'view' => ($_GET['visao'] ?? '') === 'semana' ? 'semana' : 'dia',
            'team' => $this->service->canSeeTeam($user) ? $this->service->team() : [],
            'condominiums' => (new CondominiumScope())->allowed($user),
            'longDate' => MyDay::longDate($date),
            'flash' => $flash,
            'errors' => $errors,
            'old' => $old,
            'base' => self::BASE,
            'self' => (int)$owner->id === (int)$user->id,
            // "Agendar" num chamado da lista abre o formulário já preenchido com ele.
            // Balão do SAC (ADR 0631): só quem atende escolhe se aparece como online no site.
            'sacPublic' => $this->sacPublic($user),
            'schedule' => array_values(array_filter($day['to_schedule'], static fn (array $t): bool => $t['id'] === (int)($_GET['agendar'] ?? 0)))[0] ?? null,
        ], 'Meu Dia'));
    }

    /** POST /meu-dia/compromissos */
    public function store(): void
    {
        $user = $this->guardPost();
        if ($user === null) {
            return;
        }
        $back = $this->backUrl($_POST);
        try {
            $this->service->createEvent($user, $_POST);
        } catch (MyDayError $error) {
            $old = $_POST;
            unset($old['csrf']);
            $session = new Session();
            $session->set('erpMyDayErrors', $error->errors);
            $session->set('erpMyDayOld', $old);
            $this->flash('danger', $error->getMessage());
            redirect($back . '#novo-compromisso');
            return;
        }
        $this->flash('success', 'Compromisso marcado.');
        redirect($back);
    }

    /** POST /meu-dia/compromissos/{id}/situacao */
    public function status(array $data): void
    {
        $user = $this->guardPost();
        if ($user === null) {
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $status = (string)($_POST['status'] ?? '');
        try {
            $this->service->setEventStatus($user, $id, $status);
        } catch (MyDayError $error) {
            $this->flash('danger', $error->getMessage());
            redirect($this->backUrl($_POST));
            return;
        }
        $this->flash('success', ['completed' => 'Compromisso concluído.', 'cancelled' => 'Compromisso cancelado.'][$status] ?? 'Compromisso reaberto.');
        redirect($this->backUrl($_POST));
    }

    /** POST /meu-dia/sac: a própria pessoa autoriza (ou retira) aparecer no balão do SAC do site. */
    public function sac(): void
    {
        $user = $this->guardPost();
        if ($user === null) {
            return;
        }
        if (!Access::can('support.manage', $user)) {
            $this->flash('danger', 'Só quem atende chamados aparece no balão do SAC.');
            redirect($this->backUrl($_POST));
            return;
        }
        $public = ($_POST['public'] ?? '') === '1';
        (new SacDesk())->setPublic($user, $public);
        $this->flash('success', $public ? 'Pronto: quando você estiver no sistema, o site mostra seu primeiro nome e foto no balão do SAC.' : 'Pronto: você não aparece mais no balão do SAC.');
        redirect($this->backUrl($_POST));
    }

    /* ---------------------------------------------------------------- internos */

    /** null: a pessoa não atende chamados (sem a escolha na tela). */
    private function sacPublic(User $user): ?bool
    {
        if (!Access::can('support.manage', $user)) {
            return null;
        }
        try {
            return (new SacDesk())->isPublic($user);
        } catch (\Throwable) {
            return null;
        }
    }


    /** Volta para o mesmo dia, pessoa e visão de onde o formulário saiu (só valores validados). */
    private function backUrl(array $input): string
    {
        $query = [];
        if (DateTimeImmutable::createFromFormat('!Y-m-d', (string)($input['back_date'] ?? $input['date'] ?? '')) !== false) {
            $query['data'] = (string)($input['back_date'] ?? $input['date']);
        }
        if (($person = (int)($input['back_person'] ?? 0)) > 0) {
            $query['pessoa'] = $person;
        }
        if (($input['back_view'] ?? '') === 'semana') {
            $query['visao'] = 'semana';
        }
        return erp_url(self::BASE . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    private function guard(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            $this->state($user, new MyDayError('forbidden', 'Você não tem acesso ao ERP.', 403));
            return null;
        }
        return $user;
    }

    private function guardPost(): ?User
    {
        $user = $this->guard();
        if ($user === null) {
            return null;
        }
        if (!csrf_verify($_POST)) {
            $this->flash('danger', 'Sua sessão expirou. Envie de novo.');
            redirect(erp_url(self::BASE));
            return null;
        }
        return $user;
    }

    private function noCache(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
        }
    }

    private function flash(string $type, string $text): void
    {
        (new Session())->set('erpMyDayFlash', ['type' => $type, 'text' => $text]);
    }

    private function state(User $user, MyDayError $error): void
    {
        http_response_code($error->status);
        $title = $error->status === 404 ? 'Não encontrado' : ($error->status === 403 ? 'Acesso negado' : 'Não foi possível abrir');
        echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
            'stateCode' => $error->status, 'stateTitle' => $title, 'stateMessage' => $error->getMessage(), 'withNavigation' => true,
        ], $title));
    }
}
