<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\People as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Pessoas para o cadastro de proprietário e inquilino (ADR 0162). CSRF e sessão no middleware ApiAuthenticated.
 *  GET  /api/v1/people/search?q=&role=owner|tenant|any&limit=20   busca (erp.units.view)
 *       {"data":[{"id","name","document_masked","person_type":"PF"|"PJ","links":[{"unit_id","unit_label",
 *       "condominium_slug","condominium_name","role":"owner"|"tenant"}]}]}
 *  POST /api/v1/people/{id}/links  {"unit_ids":[...],"role":"owner"|"tenant","starts_at":"AAAA-MM-DD"}
 *       vincula a pessoa a N unidades (erp.units.view + erp.residents.manage). Fora do escopo = 404.
 * 422 = parâmetro inválido; 429 = limite de buscas.
 */
final class People
{
    public function search(): void
    {
        $user = $this->authorize(['erp.units.view']);
        if ($user !== null) {
            $this->run(200, fn (): array => (new Service())->search($user, $_GET['q'] ?? null, $_GET['role'] ?? 'any', $_GET['limit'] ?? Service::MAX_LIMIT));
        }
    }

    /** @param array{id?:string} $data */
    public function links(array $data): void
    {
        $user = $this->authorize(['erp.units.view', 'erp.residents.manage']);
        if ($user === null) {
            return;
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' ? $_POST : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $this->run(201, fn (): array => (new Service())->link($user, $id, $body));
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Pessoa ou unidade não encontrada no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(array $permissions): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        foreach (array_merge(['erp.access'], $permissions) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return null;
            }
        }
        return $user;
    }
}
