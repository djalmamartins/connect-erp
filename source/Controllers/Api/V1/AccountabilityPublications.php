<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\AccountabilityPublications as Service;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Publicação da prestação de contas mensal (ADR 0179). CSRF e sessão ficam no middleware ApiAuthenticated:
 *  GET  /api/v1/accountability-publications?condominium_id=&month=      versões, sem o conteúdo
 *  GET  /api/v1/accountability-publications/{id}                        uma versão com o pacote (?format=csv baixa o CSV dela)
 *  POST /api/v1/accountability-publications {condominium_id, month, notes?}   publica (mês fechado)
 *  POST /api/v1/accountability-publications/{id}/withdraw {reason}      retira a versão atual
 * Ver exige erp.closing.view, erp.entries.view e erp.chart.view; publicar e retirar, também erp.closing.manage.
 * Fora do escopo = 404; inválido = 422; mês aberto ou versão que não é a atual = 409; CSV acima do limite = 429.
 */
final class AccountabilityPublications
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), $_GET['month'] ?? null));
        }
    }

    /** @param array{id?:string} $data */
    public function show(array $data): void
    {
        $user = $this->authorize(false);
        if ($user === null) {
            return;
        }
        $id = $this->int($data['id'] ?? null) ?? 0;
        if (($_GET['format'] ?? '') !== 'csv') {
            $this->run(fn (): array => (new Service())->find($user, $id));
            return;
        }
        try {
            $publication = (new Service())->find($user, $id);
            (new ReportExportLimit())->assertAllowed($user);
            $content = CollectionReports::csv(Accountability::csvTable($publication['payload']));
            Audit::record('export', 'erp_reports', 0, null, ['report' => 'accountability-publication', 'publication_id' => $id,
                'condominium_id' => $publication['condominium_id'], 'version' => $publication['version'], 'bytes' => strlen($content)]);
            header('Cache-Control: no-store, private');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="accountability-' . $publication['competence'] . '-v' . $publication['version'] . '.csv"');
            header('X-Content-Type-Options: nosniff');
            echo $content;
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Publicação não encontrada no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->publish($user, $this->int($body['condominium_id'] ?? null), $body['month'] ?? null, $body['notes'] ?? null), 201);
        }
    }

    /** @param array{id?:string} $data */
    public function withdraw(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->withdraw($user, $this->int($data['id'] ?? null) ?? 0, $body['reason'] ?? null));
        }
    }

    private function run(callable $action, int $status = 200): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status === 201 && isset($data['created']) && $data['created'] === false ? 200 : $status, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Publicação ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(): array
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return [null, []];
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return [null, []];
        }
        return [$user, $body];
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || !Access::can('erp.entries.view', $user)
            || !Access::can('erp.chart.view', $user) || ($manage && !Access::can('erp.closing.manage', $user))) {
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
