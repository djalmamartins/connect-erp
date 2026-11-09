<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * API REST dos chamados (docs/api/openapi.yaml, tag Tickets; ADR 0034).
 * Sessão e CSRF no middleware ApiAuthenticated; erp.access e escopo de condomínio aqui e no
 * TicketService (as mesmas regras da tela /operacional/chamados). Todo chamado vai para a fila
 * do Help Desk. Origem "api"; o portal dos moradores usará a origem "residents".
 */
final class Tickets
{
    /** GET /api/v1/tickets?condominium_id=&status=&q= */
    public function index(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        JsonResponse::send(200, ['data' => (new TicketService())->listFor($user, [
            'condominium_id' => (int)($_GET['condominium_id'] ?? 0),
            'status' => (string)($_GET['status'] ?? ''),
            'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80),
        ], (int)($_GET['limit'] ?? 100))]);
    }

    /** GET /api/v1/tickets/{id} */
    public function show(array $data): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $ticket = (new TicketService())->findFor($user, (int)($data['id'] ?? 0));
        if ($ticket === null) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Chamado não encontrado.']]);
            return;
        }
        JsonResponse::send(200, ['data' => $ticket]);
    }

    /** POST /api/v1/tickets */
    public function store(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        try {
            $raw = (string)file_get_contents('php://input');
            $body = trim($raw) === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            $input = array_intersect_key(is_array($body) ? $body : [], array_flip(['subject', 'message', 'area', 'priority', 'category', 'condominium_id', 'unit_id', 'requester_name', 'requester_email', 'requester_phone']));
            $service = new TicketService();
            $opened = $service->open($input, 'api', $user);
            JsonResponse::send(201, ['data' => $service->findFor($user, $opened['id'])]);
        } catch (TicketError $error) {
            JsonResponse::send($error->status(), ['error' => ['code' => $error->status() === 403 ? 'forbidden' : 'validation_failed', 'message' => $error->getMessage(), 'fields' => [$error->field => $error->getMessage()]]]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_create_failed', 'origin' => 'api']);
            JsonResponse::send(500, ['error' => ['code' => 'internal_error', 'message' => 'Não foi possível abrir o chamado.']]);
        }
    }

    private function user(): ?\Source\Models\User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem acesso ao ERP.']]);
            return null;
        }
        return $user;
    }
}
