<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Privacy\PersonalDataExport;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatório dos dados pessoais de um titular (LGPD art. 18, II), ADR 0328:
 *  POST /api/v1/privacy/personal-data  {"document": "...", "email": "..."}
 * POST para o CPF e o e-mail não irem para a URL nem para os logs de acesso. Exige erp.access e erp.privacy.export
 * (migration 20261116_2130, dada aos papéis com erp.users.manage). Limite de 20 buscas por 10 minutos. Auditado sem dado
 * pessoal (só o hash da busca e quantos registros saíram). Entrada inválida = 422. Não grava nada além da auditoria.
 */
final class PersonalDataReport
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.privacy.export', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        if (request_limit('erp_privacy_export', 20, 600)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitas buscas seguidas. Tente de novo em alguns minutos.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new PersonalDataExport())->export($user, $body['document'] ?? null, $body['email'] ?? null);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
            return;
        }
        [$document, $email] = PersonalDataExport::criteria($body['document'] ?? null, $body['email'] ?? null);
        Audit::record('export', 'privacy_personal_data', null, [], [
            'search' => hash('sha256', (string)$document . '|' . (string)$email),
            'records' => (int)$data['total'],
        ]);
        header('Cache-Control: no-store, private');
        JsonResponse::send(200, ['data' => $data]);
    }
}
