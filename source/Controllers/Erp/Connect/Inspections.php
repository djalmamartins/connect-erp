<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Inspections\InspectionError;
use Source\Services\Inspections\InspectionReport;
use Source\Services\Inspections\InspectionService;
use Source\Services\Inspections\ChecklistText;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * ERP v1 — Vistoria presencial (Operacional › Vistorias, CN-001).
 *
 * Tela pensada para o celular: check-in com GPS, checklist por seção, foto pela câmera,
 * conclusão e relatório (HTML, PDF e e-mail). Regras em InspectionService, as mesmas
 * da API /api/v1/inspections. Formulários com CSRF; ações respondem com redirecionamento
 * e mensagem (padrão PRG), sem depender de JavaScript.
 */
final class Inspections extends Controller
{
    private const PAGE_ID = 'CN-001';
    private const BASE = '/operacional/vistorias';

    private InspectionService $service;

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
        $this->service = new InspectionService();
    }

    /** GET /operacional/vistorias — vistorias devidas e recentes. */
    public function index(): void
    {
        $user = $this->guard(InspectionService::VIEW);
        if ($user === null) {
            return;
        }
        // Condomínio da barra superior (ADR 0030); o filtro ?condominio= troca o contexto no index.php.
        $context = CondominiumContext::id();
        $filters = ['condominium_id' => $context ?? 0, 'status' => (string)($_GET['situacao'] ?? '')];
        $board = $this->service->board($user);
        if ($context !== null) {
            $board = array_values(array_filter($board, static fn (array $due): bool => $due['condominium_id'] === $context));
        }
        $this->render($user, 'pages/inspections/index', [
            'board' => $board,
            'recent' => $this->service->recent($user, $filters),
            'filters' => $filters,
            'condominiums' => (new CondominiumScope())->allowed($user),
            'canPerform' => $this->service->can(InspectionService::PERFORM, $user),
            'canManage' => $this->service->can(InspectionService::MANAGE, $user),
        ]);
    }

    /** GET /operacional/vistorias/nova — escolha do condomínio e do modelo, e check-in. */
    public function create(): void
    {
        $user = $this->guard(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $this->render($user, 'pages/inspections/create', [
            'condominiums' => (new CondominiumScope())->allowed($user),
            'templates' => $this->service->templates(),
            'geolocations' => $this->service->geolocations($user),
            'selected' => ['condominium_id' => CondominiumContext::id() ?? 0, 'template_id' => (int)($_GET['modelo'] ?? 0)],
            'old' => $this->old(),
            'errors' => $this->errors(),
        ], 'Nova vistoria');
    }

    /** POST /operacional/vistorias — check-in. */
    public function store(): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        try {
            $id = $this->service->start($user, $_POST);
        } catch (InspectionError $error) {
            $this->back(self::BASE . '/nova?condominio=' . (int)($_POST['condominium_id'] ?? 0) . '&modelo=' . (int)($_POST['template_id'] ?? 0), $error, $_POST);
            return;
        }
        $this->flash('success', 'Check-in registrado. Bom trabalho na vistoria!');
        redirect(erp_url(self::BASE . '/' . $id));
    }

    /** GET /operacional/vistorias/{id} — checklist (em andamento) ou resultado. */
    public function show(array $data): void
    {
        $user = $this->guard(InspectionService::VIEW);
        if ($user === null) {
            return;
        }
        try {
            $inspection = $this->service->get($user, $this->id($data));
        } catch (InspectionError $error) {
            $this->state($user, $error);
            return;
        }
        $this->render($user, 'pages/inspections/show', [
            'inspection' => $inspection,
            'errors' => $this->errors(),
            'canPerform' => $this->service->can(InspectionService::PERFORM, $user),
        ], $inspection['condominium']);
    }

    /** POST /operacional/vistorias/{id}/itens/{item} — resposta e, se houver, foto do item. */
    public function answer(array $data): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        $item = (int)($data['item'] ?? 0);
        $target = self::BASE . "/{$id}#item-{$item}";
        try {
            if (isset($_POST['result'])) {
                $this->service->answer($user, $id, $item, $_POST);
            }
            $photo = $_FILES['photo'] ?? null;
            if (is_array($photo) && (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->service->addPhoto($user, $id, $item, $photo, $_POST);
            }
        } catch (InspectionError $error) {
            $this->back($target, $error, [], 'item_' . $item);
            return;
        }
        $this->flash('success', 'Item salvo.');
        redirect(erp_url($target));
    }

    /** POST /operacional/vistorias/{id}/fotos/{photo}/excluir */
    public function deletePhoto(array $data): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $this->service->deletePhoto($user, $id, (int)($data['photo'] ?? 0));
        } catch (InspectionError $error) {
            $this->back(self::BASE . "/{$id}", $error);
            return;
        }
        $this->flash('success', 'Foto removida.');
        redirect(erp_url(self::BASE . "/{$id}" . (isset($_POST['item']) ? '#item-' . (int)$_POST['item'] : '')));
    }

    /** GET /operacional/vistorias/{id}/fotos/{photo} — foto servida só para quem tem o condomínio no escopo. */
    public function photo(array $data): void
    {
        $user = Auth::user();
        if ($user === null || !Access::can('erp.access', $user)) {
            http_response_code(404);
            return;
        }
        try {
            $file = $this->service->photoFile($user, $this->id($data), (int)($data['photo'] ?? 0));
        } catch (InspectionError) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . filesize($file['path']));
        header('Cache-Control: private, max-age=3600');
        header('ETag: "' . $file['sha256'] . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        readfile($file['path']);
    }

    /** POST /operacional/vistorias/{id}/concluir */
    public function complete(array $data): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $this->service->complete($user, $id, (string)($_POST['summary'] ?? ''));
        } catch (InspectionError $error) {
            $this->back(self::BASE . "/{$id}#concluir", $error, ['summary' => (string)($_POST['summary'] ?? '')]);
            return;
        }
        $this->flash('success', 'Vistoria concluída. Agora você pode enviar o relatório.');
        redirect(erp_url(self::BASE . "/{$id}#enviar"));
    }

    /** POST /operacional/vistorias/{id}/cancelar */
    public function cancel(array $data): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $this->service->cancel($user, $id, (string)($_POST['reason'] ?? ''));
        } catch (InspectionError $error) {
            $this->back(self::BASE . "/{$id}#cancelar", $error);
            return;
        }
        $this->flash('success', 'Vistoria cancelada.');
        redirect(erp_url(self::BASE));
    }

    /** GET /operacional/vistorias/{id}/relatorio — documento para ver e imprimir. */
    public function report(array $data): void
    {
        $user = $this->guard(InspectionService::VIEW);
        if ($user === null) {
            return;
        }
        try {
            $inspection = $this->service->get($user, $this->id($data));
        } catch (InspectionError $error) {
            $this->state($user, $error);
            return;
        }
        $base = self::BASE . '/' . $inspection['id'] . '/fotos/';
        echo (new InspectionReport($this->service))->html($inspection, static fn(int $photo): string => erp_url($base . $photo));
    }

    /** GET /operacional/vistorias/{id}/relatorio/pdf */
    public function reportPdf(array $data): void
    {
        $user = $this->guard(InspectionService::VIEW);
        if ($user === null) {
            return;
        }
        try {
            $inspection = $this->service->get($user, $this->id($data));
        } catch (InspectionError $error) {
            $this->state($user, $error);
            return;
        }
        $pdf = (new InspectionReport($this->service))->pdf($user, $inspection);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . InspectionReport::filename($inspection) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    /** POST /operacional/vistorias/{id}/enviar — relatório por e-mail (fila do ambiente send). */
    public function send(array $data): void
    {
        $user = $this->guardPost(InspectionService::PERFORM);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        if (request_limit('erp_inspection_send', 5, 600)) {
            $this->back(self::BASE . "/{$id}#enviar", new InspectionError('too_many_requests', 'Muitos envios em pouco tempo. Aguarde alguns minutos.', 429));
            return;
        }
        try {
            $inspection = $this->service->get($user, $id);
            $recipients = InspectionService::recipients((string)($_POST['recipients'] ?? ''));
            $queued = (new InspectionReport($this->service))->queue($user, $inspection, $recipients, (string)($_POST['message'] ?? ''));
        } catch (InspectionError $error) {
            $this->back(self::BASE . "/{$id}#enviar", $error, ['recipients' => (string)($_POST['recipients'] ?? ''), 'message' => (string)($_POST['message'] ?? '')]);
            return;
        }
        $this->flash($queued > 0 ? 'success' : 'danger', $queued > 0 ? "Relatório na fila de envio para {$queued} e-mail(s)." : 'Não foi possível colocar o relatório na fila de envio.');
        redirect(erp_url(self::BASE . "/{$id}#enviar"));
    }

    /** GET /operacional/vistorias/configurar — modelos, planos e localização dos condomínios. */
    public function settings(): void
    {
        $user = $this->guard(InspectionService::MANAGE);
        if ($user === null) {
            return;
        }
        $editing = null;
        if (isset($_GET['modelo'])) {
            $editing = $this->service->template((int)$_GET['modelo']);
        }
        $this->render($user, 'pages/inspections/settings', [
            'templates' => $this->service->templates(false),
            'editing' => $editing,
            'editingText' => $editing ? ChecklistText::format($editing['items']) : '',
            'plans' => $this->service->plans($user),
            'condominiums' => (new CondominiumScope())->allowed($user),
            'geolocations' => $this->service->geolocations($user),
            'old' => $this->old(),
            'errors' => $this->errors(),
        ], 'Configurar vistorias');
    }

    /** POST /operacional/vistorias/modelos */
    public function saveTemplate(): void
    {
        $user = $this->guardPost(InspectionService::MANAGE);
        if ($user === null) {
            return;
        }
        $id = (int)($_POST['id'] ?? 0) ?: null;
        try {
            $this->service->saveTemplate($user, $id, $_POST);
        } catch (InspectionError $error) {
            $this->back(self::BASE . '/configurar' . ($id ? "?modelo={$id}" : '') . '#modelo', $error, $_POST);
            return;
        }
        $this->flash('success', 'Modelo de checklist salvo.');
        redirect(erp_url(self::BASE . '/configurar#modelos'));
    }

    /** POST /operacional/vistorias/planos */
    public function savePlan(): void
    {
        $user = $this->guardPost(InspectionService::MANAGE);
        if ($user === null) {
            return;
        }
        try {
            $this->service->savePlan($user, $_POST);
        } catch (InspectionError $error) {
            $this->back(self::BASE . '/configurar#planos', $error, $_POST);
            return;
        }
        $this->flash('success', 'Plano de vistoria salvo.');
        redirect(erp_url(self::BASE . '/configurar#planos'));
    }

    /** POST /operacional/vistorias/localizacao */
    public function saveLocation(): void
    {
        $user = $this->guardPost(InspectionService::MANAGE);
        if ($user === null) {
            return;
        }
        try {
            $this->service->saveGeolocation($user, (int)($_POST['condominium_id'] ?? 0), $_POST);
        } catch (InspectionError $error) {
            $this->back(self::BASE . '/configurar#localizacao', $error, $_POST);
            return;
        }
        $this->flash('success', 'Localização do condomínio salva.');
        redirect(erp_url(self::BASE . '/configurar#localizacao'));
    }

    /* ---------------------------------------------------------------- internos */

    private function guard(string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can($permission, $user)) {
            $this->state($user, new InspectionError('forbidden', 'Você não tem permissão para esta área de vistorias.', 403));
            return null;
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        return $user;
    }

    private function guardPost(string $permission): ?User
    {
        $user = $this->guard($permission);
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

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }

    private function render(User $user, string $template, array $data, ?string $title = null): void
    {
        $session = new Session();
        $flash = $session->has('erpInspectionFlash') ? (array)$session->erpInspectionFlash : null;
        $session->unset('erpInspectionFlash');
        echo $this->view->render($template, (new Panel())->layoutData($user, self::PAGE_ID, $data + [
            'flash' => $flash,
            'base' => self::BASE,
        ], $title));
    }

    private function state(User $user, InspectionError $error): void
    {
        http_response_code($error->status);
        $title = $error->status === 404 ? 'Vistoria não encontrada' : ($error->status === 403 ? 'Acesso negado' : 'Não foi possível concluir');
        $message = $error->status === 404 ? 'A vistoria não existe ou não está no seu escopo de acesso.' : $error->getMessage();
        echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
            'stateCode' => $error->status, 'stateTitle' => $title, 'stateMessage' => $message, 'withNavigation' => true,
        ], $title));
    }

    private function flash(string $type, string $text): void
    {
        (new Session())->set('erpInspectionFlash', ['type' => $type, 'text' => $text]);
    }

    /** Volta ao formulário com a mensagem, os erros por campo e os valores digitados. */
    private function back(string $path, InspectionError $error, array $old = [], ?string $errorKey = null): void
    {
        $errors = $error->errors;
        if ($errorKey !== null && $errors !== []) {
            $errors = [$errorKey => implode(' ', $errors)];
        }
        unset($old['csrf']);
        $session = new Session();
        $session->set('erpInspectionErrors', $errors);
        $session->set('erpInspectionOld', $old);
        $this->flash('danger', $error->getMessage());
        redirect(erp_url($path));
    }

    /** @return array<string, string> */
    private function errors(): array
    {
        $session = new Session();
        $errors = $session->has('erpInspectionErrors') ? (array)$session->erpInspectionErrors : [];
        $session->unset('erpInspectionErrors');
        return $errors;
    }

    /** @return array<string, mixed> */
    private function old(): array
    {
        $session = new Session();
        $old = $session->has('erpInspectionOld') ? (array)$session->erpInspectionOld : [];
        $session->unset('erpInspectionOld');
        return $old;
    }
}
