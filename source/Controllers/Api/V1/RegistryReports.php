<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\RegistryReports as Reports;
use Source\Services\Erp\ReportExportLimit;
use DomainException;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatórios cadastrais e administrativos (ADR 0084), somente leitura; ?format=csv com o CSV seguro do ADR 0081:
 *  GET /api/v1/reports/reservations?from=&to=&status=      AP-085 (erp.reservations.view)
 *  GET /api/v1/reports/units                               AP-086 (erp.units.view)
 *  GET /api/v1/reports/people?relationship=                AP-087 (erp.residents.view; documento, e-mail e telefone mascarados)
 *  GET /api/v1/reports/condominiums                        AP-089 (erp.condominiums.view)
 *  GET /api/v1/reports/occurrences?from=&to=&status=       AP-090 (erp.occurrences.view)
 *  GET /api/v1/reports/pending-payments?until=             AP-093 (erp.entries.view)
 *  GET /api/v1/reports/closings?from=&to=                  AP-094 (erp.closing.view)
 *  GET /api/v1/reports/moves?from=&to=                     AP-095 (erp.moves.view)
 *  GET /api/v1/reports/addresses                           AP-096 (erp.condominiums.view)
 *  GET /api/v1/reports/logs?from=&to=&entity=&action=      AP-097 (erp.audit.view e acesso a todos os condomínios)
 *  GET /api/v1/reports/access-people                       AP-098 (erp.audit.view)
 *  GET /api/v1/reports/sent-boletos?from=&to=              AP-099 (erp.notifications.view e erp.bank_charges.view)
 */
final class RegistryReports
{
    public function reservations(): void
    {
        $this->run('reservations', ['erp.reservations.view'], fn (Reports $r, User $u, ?int $c): array => $r->reservations($u, $c, $this->text('from'), $this->text('to'), $this->text('status') ?: null));
    }

    public function units(): void
    {
        $this->run('units', ['erp.units.view'], fn (Reports $r, User $u, ?int $c): array => $r->units($u, $c));
    }

    public function people(): void
    {
        $this->run('people', ['erp.residents.view'], fn (Reports $r, User $u, ?int $c): array => $r->people($u, $c, $this->text('relationship') ?: null));
    }

    public function condominiums(): void
    {
        $this->run('condominiums', ['erp.condominiums.view'], fn (Reports $r, User $u, ?int $c): array => $r->condominiums($u));
    }

    public function occurrences(): void
    {
        $this->run('occurrences', ['erp.occurrences.view'], fn (Reports $r, User $u, ?int $c): array => $r->occurrences($u, $c, $this->text('from'), $this->text('to'), $this->text('status') ?: null));
    }

    public function pendingPayments(): void
    {
        $this->run('pending-payments', ['erp.entries.view'], fn (Reports $r, User $u, ?int $c): array => $r->pendingPayments($u, $c, $this->text('until') ?: null));
    }

    public function closings(): void
    {
        $this->run('closings', ['erp.closing.view'], fn (Reports $r, User $u, ?int $c): array => $r->closings($u, $c, $this->text('from'), $this->text('to')));
    }

    public function moves(): void
    {
        $this->run('moves', ['erp.moves.view'], fn (Reports $r, User $u, ?int $c): array => $r->moves($u, $c, $this->text('from'), $this->text('to')));
    }

    public function addresses(): void
    {
        $this->run('addresses', ['erp.condominiums.view'], fn (Reports $r, User $u, ?int $c): array => $r->addresses($u));
    }

    public function logs(): void
    {
        $this->run('logs', ['erp.audit.view'], fn (Reports $r, User $u, ?int $c): array => $r->logs($u, $this->text('from'), $this->text('to'),
            $this->text('entity') ?: null, $this->text('action') ?: null));
    }

    public function accessPeople(): void
    {
        $this->run('access-people', ['erp.audit.view'], fn (Reports $r, User $u, ?int $c): array => $r->accessPeople($u, $c));
    }

    public function sentBoletos(): void
    {
        $this->run('sent-boletos', ['erp.notifications.view', 'erp.bank_charges.view'], fn (Reports $r, User $u, ?int $c): array => $r->sentBoletos($u, $c, $this->text('from'), $this->text('to')));
    }

    /** @param list<string> $permissions */
    private function run(string $kind, array $permissions, callable $report): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        foreach (array_merge(['erp.access'], $permissions) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $condominium = $condominium === false ? null : (int)$condominium;
        try {
            $data = $report(new Reports(), $user, $condominium);
            header('Cache-Control: no-store, private');
            if ($this->text('format') === 'csv') {
                (new ReportExportLimit())->assertAllowed($user);   // ADR 0087 (B5): até 30 por hora
                $content = CollectionReports::csv(Reports::csvTable($kind, $data));
                Audit::record('export', 'erp_reports', 0, null, ['report' => $kind, 'condominium_id' => $condominium, 'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $kind . '-' . date('Y-m-d') . '.csv"');
                header('X-Content-Type-Options: nosniff');
                echo $content;
                return;
            }
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryForbidden) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Os logs exigem acesso a todos os condomínios.']]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
        }
    }

    private function text(string $name): string
    {
        return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
    }
}
