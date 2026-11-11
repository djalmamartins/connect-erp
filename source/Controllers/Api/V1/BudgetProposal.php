<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BudgetProposal as Service;
use Source\Support\Access;

/**
 * Proposta de previsão orçamentária (ADR 0187):
 *  GET  /api/v1/budgets/proposal?condominium_id=&year=&adjustment=&distribution=flat|seasonal
 *  POST /api/v1/budgets/proposal {condominium_id, year, adjustment?, distribution?, chart_account_ids?}
 * Ver exige erp.settings.view e erp.entries.view; gravar, também erp.settings.manage (CSRF pelo ApiAuthenticated).
 * Fora do escopo = 404; entrada inválida = 422; ano com mês fechado = 409. A gravação é auditada pelo setBudget.
 */
final class BudgetProposal
{
    public function show(): void
    {
        $user = $this->authorize(false);
        if ($user === null) {
            return;
        }
        $this->run(fn () => (new Service())->suggest($user, $this->int($_GET['condominium_id'] ?? null), $_GET['year'] ?? null,
            $_GET['adjustment'] ?? null, $_GET['distribution'] ?? null));
    }

    public function apply(): void
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $ids = $body['chart_account_ids'] ?? null;
        if ($ids !== null && !is_array($ids)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Contas: informe a lista de ids.']]);
            return;
        }
        $this->run(fn () => (new Service())->apply($user, $this->int($body['condominium_id'] ?? null), $body['year'] ?? null,
            $body['adjustment'] ?? null, $body['distribution'] ?? null, $ids === null ? null : array_values($ids)));
    }

    private function run(callable $action): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.settings.view', $user) || !Access::can('erp.entries.view', $user)
            || ($manage && !Access::can('erp.settings.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function int(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $id === null ? null : (int)$id;
    }
}
