<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Assemblies as Service;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Assembleia e votação (ADR 0088):
 *  GET  /api/v1/assemblies?condominium_id=                     lista
 *  POST /api/v1/assemblies {condominium_id, kind, mode, title, location, first_call_at, second_call_at, items[]}
 *  GET  /api/v1/assemblies/{id}                                 detalhe com pauta, presença e ata
 *  POST /api/v1/assemblies/{id}/convene                          edital pela outbox (também erp.announcements.manage)
 *  POST /api/v1/assemblies/{id}/open
 *  POST /api/v1/assemblies/{id}/attendance {unit_id, attendee_name, is_proxy, proxy_document_id}
 *  POST /api/v1/assemblies/{id}/items/{item}/start
 *  POST /api/v1/assemblies/{id}/items/{item}/votes {unit_id, choice}
 *  POST /api/v1/assemblies/{id}/items/{item}/close
 *  POST /api/v1/assemblies/{id}/close
 *  POST /api/v1/assemblies/{id}/cancel {reason}
 *  GET  /api/v1/assemblies/{id}/attendance-report[?format=csv]   AP-088
 *  GET  /api/v1/assemblies/{id}/results[?format=csv]             AP-092
 * Ver exige erp.assemblies.view; mudar, erp.assemblies.manage. CSRF e sessão no middleware ApiAuthenticated.
 */
final class Assemblies
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null)));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->create($user, $body));
        }
    }

    /** @param array{id?:string} $data */
    public function show(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function convene(array $data): void
    {
        [$user] = $this->write('erp.announcements.manage');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->convene($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function open(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->open($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function checkIn(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->checkIn($user, $this->id($data), $body));
        }
    }

    /** @param array{id?:string, item?:string} $data */
    public function startItem(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->startVoting($user, $this->id($data), $this->id($data, 'item')));
        }
    }

    /** @param array{id?:string, item?:string} $data */
    public function vote(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->vote($user, $this->id($data), $this->id($data, 'item'), $body['unit_id'] ?? null, $body['choice'] ?? null));
        }
    }

    /** @param array{id?:string, item?:string} $data */
    public function closeItem(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->closeItem($user, $this->id($data), $this->id($data, 'item')));
        }
    }

    /** @param array{id?:string} $data */
    public function close(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->close($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function cancel(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancel($user, $this->id($data), $body['reason'] ?? null));
        }
    }

    /** @param array{id?:string} $data */
    public function attendanceReport(array $data): void
    {
        // ADR 0094: o valor vencido por unidade só para quem já vê a inadimplência.
        $this->report($data, 'attendance', static fn (Service $s, User $u, int $id): array => $s->attendanceReport($u, $id, Access::can('erp.agreements.view', $u)));
    }

    /** @param array{id?:string} $data */
    public function results(array $data): void
    {
        $this->report($data, 'results', static fn (Service $s, User $u, int $id): array => $s->resultsReport($u, $id));
    }

    private function report(array $data, string $kind, callable $report): void
    {
        $user = $this->authorize(false);
        if ($user === null) {
            return;
        }
        $id = $this->id($data);
        try {
            $result = $report(new Service(), $user, $id);
            header('Cache-Control: no-store, private');
            if (($_GET['format'] ?? '') === 'csv') {
                try {
                    (new ReportExportLimit())->assertAllowed($user);   // ADR 0087 (B5)
                } catch (DomainException $exception) {
                    JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
                    return;
                }
                $content = CollectionReports::csv(Service::csvTable($kind, $result));
                Audit::record('export', 'erp_reports', 0, null, ['report' => 'assembly_' . $kind, 'assembly_id' => $id, 'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="assembleia-' . $id . '-' . $kind . '.csv"');
                header('X-Content-Type-Options: nosniff');
                echo $content;
                return;
            }
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Assembleia não encontrada no seu escopo.']]);
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Assembleia não encontrada no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'assembly_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(?string $extra = null): array
    {
        $user = $this->authorize(true, $extra);
        if ($user === null) {
            return [null, []];
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return [null, []];
        }
        return [$user, $body];
    }

    private function authorize(bool $manage, ?string $extra = null): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.assemblies.view', $user) || ($manage && !Access::can('erp.assemblies.manage', $user))
            || ($extra !== null && !Access::can($extra, $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function id(array $data, string $key = 'id'): int
    {
        return $this->int($data[$key] ?? null) ?? 0;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
