<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Imports\StatementLineCandidates;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Dados das telas de importação (ADR 0058 e 0074), lidos no servidor pelo mesmo serviço da API:
 * as importações do tipo da página no condomínio da barra superior, o detalhe de uma (?importacao=ID,
 * com as linhas do extrato no OFX) e, no extrato (AP-140), a fila de linhas pendentes com os
 * lançamentos candidatos da linha escolhida (?linha=ID). Enviar, aplicar e resolver são pela API
 * REST (erp.js, data-erp-imports). Sem banco ou sem erp.imports.view, importReady false = demonstração.
 */
final class ImportScreens
{
    /** Página => tipo da importação (Imports::KINDS). */
    public static function pages(): array
    {
        $pages = [];
        foreach (Imports::KINDS as $kind => $definition) {
            $pages[$definition['page']] = $kind;
        }
        return $pages;
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $kind = self::pages()[$id] ?? null;
        $data = ['importReady' => false];
        if ($kind === null || Connect::databaseDisabled() || !Access::can('erp.imports.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        $data += ['importKind' => $kind, 'importContext' => $context, 'importDetail' => null, 'importError' => null, 'importBankAccounts' => [],
            'pendingLines' => null, 'lineDetail' => null,
            'canImport' => Access::can('erp.imports.manage', $user) && Access::can(Imports::KINDS[$kind]['permission'], $user),
            'canResolveLines' => Access::can('erp.imports.manage', $user) && Access::can('erp.entries.manage', $user)];
        try {
            $service = new Imports();
            $data['imports'] = $service->list($user, $context, ['kind' => $kind]);
            $detail = filter_var($_GET['importacao'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($detail !== false && $detail !== null) {
                try {
                    $found = $service->find($user, (int)$detail);
                    if ($found['kind'] === $kind) {
                        $data['importDetail'] = $found;
                    } else {
                        $data['importError'] = 'Esta importação é de outra página.';
                    }
                } catch (RegistryNotFound) {
                    $data['importError'] = 'Importação não encontrada no seu escopo.';
                }
            }
            if ($kind === 'ofx') {
                $data['importBankAccounts'] = EntryShortcutScreens::forContext(
                    (new RegistryService())->references($user, RegistryService::definition('receivables'))['bank_account_id'] ?? [], $context);
                $data['pendingLines'] = $service->pendingLines($user, $context);
                $line = filter_var($_GET['linha'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($line !== false && $line !== null) {
                    try {
                        $data['lineDetail'] = (new StatementLineCandidates())->forLine($user, (int)$line);
                    } catch (RegistryNotFound) {
                        $data['importError'] = 'Linha do extrato não encontrada no seu escopo.';
                    }
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'import_screen_failed', 'page' => $id]);
            return ['importReady' => false];
        }
        $data['importReady'] = true;
        return $data;
    }

    /** Rótulo do que a aplicação gravou (summary.applied), para o resumo. */
    public static function appliedLabel(string $key): string
    {
        return [
            'entries' => 'Lançamentos', 'units' => 'Unidades', 'readings' => 'Leituras', 'amount_cents' => 'Valor',
            'lines' => 'Linhas novas', 'matched' => 'Conferidas', 'unmatched' => 'Pendentes', 'already' => 'Já importadas',
        ][$key] ?? $key;
    }

    /**
     * Linha do modelo de planilha (regra pura): as colunas obrigatórias e depois as opcionais, separadas
     * por ponto e vírgula, como o leitor aceita. OFX não tem modelo.
     */
    public static function templateHeader(string $kind): ?string
    {
        $definition = Imports::KINDS[$kind] ?? null;
        if ($definition === null || $kind === 'ofx') {
            return null;
        }
        return implode(';', array_merge($definition['required'], $definition['optional']));
    }
}
