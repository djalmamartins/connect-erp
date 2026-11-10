<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Support\HelpSuggestions;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Artigos da Central de Ajuda para o ERP (ADR 0153): o formulário de chamado sugere artigos
 * enquanto a pessoa digita o assunto. Só leitura de conteúdo publicado; exige erp.access.
 */
final class Help
{
    /** GET /api/v1/help/articles?q=&limit= */
    public function articles(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem acesso ao ERP.']]);
            return;
        }
        if (request_limit('api_help_articles', 120, 60)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitas buscas em pouco tempo.']]);
            return;
        }
        try {
            $limit = (int)filter_var($_GET['limit'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['default' => 5, 'min_range' => 1, 'max_range' => 10]]);
            JsonResponse::send(200, ['data' => (new HelpSuggestions())->search((string)($_GET['q'] ?? ''), $limit, true)]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'support', ['event_type' => 'help_suggestions_failed', 'users_id' => (int)$user->id]);
            JsonResponse::send(200, ['data' => []]);
        }
    }
}
