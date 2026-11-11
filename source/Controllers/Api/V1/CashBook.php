<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CashBook as Service;
use Source\Support\Access;

/**
 * Livro caixa mensal (CN-007, ADR 0174), somente leitura:
 *  GET /api/v1/reports/cash-book?condominium_id=&competence=AAAA-MM
 * Saldo anterior, movimento do mês com saldo corrido, anexos numerados (sem caminho do arquivo), conferência com
 * as contas bancárias e despesas sem comprovante. O PDF e o pacote com anexos saem da tela do ERP. Exige
 * erp.entries.view; condomínio obrigatório (fora do escopo = 404).
 */
final class CashBook
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe o condomínio (condominium_id).']]);
            return;
        }
        try {
            $data = (new Service())->build($user, (int)$condominium, is_string($_GET['competence'] ?? null) ? $_GET['competence'] : '');
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
