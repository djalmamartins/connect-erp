<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Support\TicketAttachmentAccess;

/**
 * GET /api/v1/ticket-attachments/{id} (ADR 0339): baixa o anexo de um chamado, só para quem pode ver o chamado (equipe do
 * Help Desk, ERP no escopo do condomínio, morador dono do chamado; sessão ou Bearer do app). 404 em qualquer recusa.
 */
final class TicketAttachments
{
    public function show(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $file = $id > 0 ? (new TicketAttachmentAccess())->find($user, $id) : null;
        if ($file === null) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Anexo não encontrado.']]);
            return;
        }
        $mime = (string)((new \finfo(FILEINFO_MIME_TYPE))->file($file['path']) ?: 'application/octet-stream');
        $name = preg_replace('/[^\w.\- ]+/u', '_', $file['name']) ?: 'anexo';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($file['path']));
        header("Content-Disposition: attachment; filename=\"" . str_replace('"', '', $name) . "\"; filename*=UTF-8''" . rawurlencode($file['name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file['path']);
    }
}
