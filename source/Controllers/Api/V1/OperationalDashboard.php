<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\FinancialDashboard;
use Source\Services\Erp\OperationalDashboard as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Dashboard e continuações do Operacional (ADR 0053):
 *  GET  /api/v1/dashboard?condominium_id=          números e alertas no contexto (erp.access)
 *  GET  /api/v1/dashboard/finance?condominium_id=&competence=AAAA-MM   receitas e despesas, inadimplência
 *       por atraso e quadro por condomínio (erp.entries.view), ADR 0157
 *  GET  /api/v1/dashboard/agenda?condominium_id=   próximos 30 dias (assembleias, manutenções, reservas)
 *  GET  /api/v1/dashboard/activities?condominium_id=  últimas ações da equipe (erp.audit.view)
 *  POST /api/v1/reservations/{id}/call-waitlist    chama o primeiro da lista de espera (erp.reservations.manage)
 *  POST /api/v1/maintenances/{id}/next             gera a próxima manutenção (erp.maintenances.manage)
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class OperationalDashboard
{
    public function show(): void
    {
        // Painel operacional só para quem vê as unidades (ADR 0111): o parceiro (erp_partner) não vê contagens.
        $user = $this->authorize('erp.units.view');
        if ($user === null) {
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->run(200, fn () => (new Service())->summary($user, $condominium === false || $condominium === null ? null : (int)$condominium));
    }

    public function finance(): void
    {
        $user = $this->authorize('erp.entries.view');
        if ($user === null) {
            return;
        }
        $competence = $_GET['competence'] ?? null;
        if ($competence !== null && (!is_string($competence) || !\Source\Services\Erp\RecurringEntries::isCompetence($competence))) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Competência inválida (AAAA-MM).']]);
            return;
        }
        $this->run(200, fn () => (new FinancialDashboard())->finance($user, $this->condominium(), FinancialDashboard::competenceOrCurrent($competence)));
    }

    public function agenda(): void
    {
        $user = $this->authorize(null);
        if ($user === null) {
            return;
        }
        $modules = FinancialDashboard::agendaModules($user);
        if (!in_array(true, $modules, true)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $this->run(200, fn () => ['items' => (new FinancialDashboard())->agenda($user, $this->condominium(), $modules)]);
    }

    public function activities(): void
    {
        $user = $this->authorize('erp.audit.view');
        if ($user !== null) {
            $this->run(200, fn () => ['items' => (new FinancialDashboard())->activities($user, $this->condominium())]);
        }
    }

    private function condominium(): ?int
    {
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $condominium === false || $condominium === null ? null : (int)$condominium;
    }

    public function callWaitlist(array $data): void
    {
        $user = $this->authorize('erp.reservations.manage');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->callWaitlist($user, $this->id($data)));
        }
    }

    public function nextMaintenance(array $data): void
    {
        $user = $this->authorize('erp.maintenances.manage');
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->nextMaintenance($user, $this->id($data)));
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro ou condomínio não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(?string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || ($permission !== null && !Access::can($permission, $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
