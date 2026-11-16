<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\EntryCopy as Service;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Copiar lançamentos selecionados (ADR 0313, modelo 7):
 *  POST /api/v1/entries/copy {condominium_id, entry_ids[], competence}
 * Exige erp.access, erp.entries.view e erp.entries.manage; CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada
 * inválida ou cópia recusada = 422 (errors e _entry; nada é gravado).
 */
final class EntryCopy
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->copy($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(201, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            $errors = $exception->errors;
            $entry = (string)($errors['_entry'] ?? '');
            unset($errors['_entry']);
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Nada foi copiado. ' . ($entry !== '' ? $entry . ': ' : '') . (string)(array_values($errors)[0] ?? 'dado inválido.'),
                'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
