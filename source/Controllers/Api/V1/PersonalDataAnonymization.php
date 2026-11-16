<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Privacy\PersonalDataAnonymizer;
use Source\Services\Privacy\PersonalDataExport;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Anonimização de quem saiu (LGPD), ADR 0330:
 *  POST /api/v1/privacy/anonymize  {"document"|"email", "reason", "confirm": true}
 * Sem confirm = prévia (o que muda e o que impede). Exige erp.access, erp.privacy.export e erp.users.manage. Limite de 10
 * por 10 minutos. Recusa com 409 quando a pessoa ainda mora/paga ou tem conta em aberto. Auditado só com o hash da busca,
 * o motivo e as quantidades. Não tem volta: os dados apagados só voltam pelo backup.
 */
final class PersonalDataAnonymization
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.privacy.export', $user) || !Access::can('erp.users.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        if (request_limit('erp_privacy_anonymize', 10, 600)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitos pedidos seguidos. Tente de novo em alguns minutos.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new PersonalDataAnonymizer())->run($user, $body);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
            return;
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
            return;
        }
        if ($data['confirmed']) {
            [$document, $email] = PersonalDataExport::criteria($body['document'] ?? null, $body['email'] ?? null);
            $counts = array_map(static fn ($ids): int => is_array($ids) ? count($ids) : 0, array_diff_key($data['plan'], ['kept' => true]));
            Audit::record('anonymize', 'privacy_personal_data', null, [], [
                'search' => hash('sha256', (string)$document . '|' . (string)$email),
                'reason' => mb_substr(trim((string)$body['reason']), 0, 500),
                'counts' => $counts,
            ]);
        }
        header('Cache-Control: no-store, private');
        JsonResponse::send(200, ['data' => $data]);
    }
}
