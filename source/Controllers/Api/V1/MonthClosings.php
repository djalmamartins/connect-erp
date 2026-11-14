<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\MonthClosing;
use Source\Support\Access;

/**
 * Fechamento mensal (AP-030, ADR 0031):
 *  GET  /api/v1/closings?condominium_id=&competence=   resumo e situação (erp.closing.view)
 *  POST /api/v1/closings        {condominium_id, competence}          fecha (erp.closing.manage)
 *  POST /api/v1/closings/reopen {condominium_id, competence, reason}  reabre (erp.closing.manage)
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class MonthClosings
{
    public function show(): void
    {
        $user = $this->authorize('view');
        if ($user === null) {
            return;
        }
        $this->run(fn () => (new MonthClosing())->status($user, $this->int($_GET['condominium_id'] ?? null), (string)($_GET['competence'] ?? '')));
    }

    public function close(): void
    {
        $user = $this->authorize('manage');
        $body = $user === null ? null : $this->body();
        if ($user === null || $body === null) {
            return;
        }
        $this->run(function () use ($user, $body): array {
            $closed = (new MonthClosing())->close($user, $this->int($body['condominium_id'] ?? null), (string)($body['competence'] ?? ''));
            // Link da prestação de contas aos moradores (ADR 0301); falha aqui não desfaz o fechamento.
            $closed['accountability_links'] = (new \Source\Services\Erp\AccountabilityLinks())->afterClose($user, $closed['condominium_id'], $closed['competence']);
            return $closed;
        });
    }

    public function reopen(): void
    {
        $user = $this->authorize('manage');
        $body = $user === null ? null : $this->body();
        if ($user === null || $body === null) {
            return;
        }
        $this->run(fn () => (new MonthClosing())->reopen($user, $this->int($body['condominium_id'] ?? null), (string)($body['competence'] ?? ''), (string)($body['reason'] ?? '')));
    }

    private function run(callable $action): void
    {
        try {
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $action()]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'closing_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || ($action === 'manage' && !Access::can('erp.closing.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function body(): ?array
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return null;
        }
        return $body;
    }

    private function int(mixed $value): int
    {
        return (int)(filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
