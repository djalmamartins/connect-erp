<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;

/**
 * API REST das propostas do site (CN-011, ADR 0644; docs/api/openapi.yaml, tag Proposals). As mesmas regras da tela
 * do ERP e do Studio: só quem tem proposals.manage. Sessão e CSRF no middleware ApiAuthenticated.
 * O pedido em si continua chegando pelo formulário público do site (ProposalService::submit), não por esta API.
 */
final class Proposals
{
    /** GET /api/v1/proposals?search=&status=&page=&per_page= */
    public function index(): void
    {
        $this->run(function (ProposalDesk $desk): array {
            $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
            if ($status !== '' && !isset(ProposalDesk::STATUSES[$status])) {
                throw new ProposalDeskError('validation_failed', 'Situação inválida.', 422, ['status' => 'Use uma das situações: ' . implode(', ', array_keys(ProposalDesk::STATUSES)) . '.']);
            }
            $list = $desk->list(is_string($_GET['search'] ?? null) ? $_GET['search'] : '', $status,
                max(1, (int)($_GET['page'] ?? 1)), (int)($_GET['per_page'] ?? ProposalDesk::PER_PAGE));
            return [200, ['data' => $list['data'], 'meta' => array_diff_key($list, ['data' => true]) + ['new' => $desk->newCount()]]];
        });
    }

    /** GET /api/v1/proposals/{id} */
    public function show(array $data): void
    {
        $this->run(function (ProposalDesk $desk) use ($data): array {
            $proposal = $desk->find((int)($data['id'] ?? 0));
            if ($proposal === null) {
                throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
            }
            return [200, ['data' => $proposal + ['responses' => $desk->responses($proposal['id'])]]];
        });
    }

    /** POST /api/v1/proposals/{id}/status  {"status": "...", "assigned_to": 3|null} */
    public function status(array $data): void
    {
        $this->run(function (ProposalDesk $desk) use ($data): array {
            $body = $this->body();
            $assigned = $body['assigned_to'] ?? null;
            if ($assigned !== null && (!is_int($assigned) || $assigned < 1)) {
                throw new ProposalDeskError('validation_failed', 'Responsável inválido.', 422, ['assigned_to' => 'Informe o id de alguém da equipe ou null.']);
            }
            return [200, ['data' => $desk->update((int)($data['id'] ?? 0), is_string($body['status'] ?? null) ? $body['status'] : '', $assigned)]];
        });
    }

    private function run(callable $action): void
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!ProposalDesk::allowed($user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão para as propostas do site.']]);
            return;
        }
        try {
            [$status, $payload] = $action(new ProposalDesk());
            JsonResponse::send($status, $payload);
        } catch (ProposalDeskError $error) {
            $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
            if ($error->fields !== []) {
                $body['fields'] = $error->fields;
            }
            JsonResponse::send($error->status, ['error' => $body]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }
}
