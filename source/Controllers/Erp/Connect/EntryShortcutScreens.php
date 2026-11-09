<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Dados das telas dos lançamentos avulsos (ADR 0057 e 0073), lidos no servidor pelos mesmos serviços
 * da API: AP-026 desconto (contas a receber em aberto), AP-027 multa avulsa e AP-028 cobranças extras
 * (erp_extra_charges) e AP-029 transferência entre contas. Gravar e cancelar são pela API REST
 * (erp.js, data-erp-shortcuts), com CSRF no cabeçalho e a permissão conferida lá.
 * Sem banco ou sem a permissão de ver, shortcutReady false = a tela segue com a demonstração.
 */
final class EntryShortcutScreens
{
    /** Página => tela própria. */
    public const PAGES = [
        'AP-026' => 'pages/finance/discount',
        'AP-027' => 'pages/finance/extra-charges',
        'AP-028' => 'pages/finance/extra-charges',
        'AP-029' => 'pages/finance/bank-transfers',
    ];

    /** Lançar despesa e receita (AP-024 e AP-025, ADR 0057) são o "novo" de contas a pagar e a receber. */
    public const NEW_ENTRY_ROUTES = [
        '/financeiro/lancar-despesa' => '/financeiro/contas-a-pagar/novo',
        '/financeiro/lancar-receita' => '/financeiro/contas-a-receber/novo',
    ];

    /** Tipo da origem de cada página de cobrança. */
    public const CHARGE_PAGES = ['AP-027' => 'fine', 'AP-028' => 'extra'];

    /** @return array<string, mixed> */
    public function data(User $user, string $id, callable $condominiumNames): array
    {
        $data = ['shortcutReady' => false, 'shortcutError' => null];
        $permission = isset(self::CHARGE_PAGES[$id]) ? 'erp.invoices' : 'erp.entries';
        if (Connect::databaseDisabled() || !Access::can($permission . '.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        $data['shortcutCanManage'] = Access::can($permission . '.manage', $user);
        try {
            $registry = new RegistryService();
            if ($id === 'AP-026') {
                $q = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
                $data['discountQuery'] = $q;
                $data['discountEntries'] = $registry->list($user, 'receivables', ['condominium_id' => $context, 'status' => 'active', 'q' => $q, 'page' => $_GET['pagina'] ?? 1]);
                $data['discountUnits'] = array_column($registry->units($user), 'label', 'id');
            } elseif (isset(self::CHARGE_PAGES[$id])) {
                $kind = self::CHARGE_PAGES[$id];
                $status = in_array($_GET['situacao'] ?? null, ['active', 'cancelled'], true) ? $_GET['situacao'] : null;
                $service = new EntryShortcuts();
                $data['chargeKind'] = $kind;
                $data['chargeStatus'] = $status;
                $data['charges'] = $service->listCharges($user, $context, ['kind' => $kind, 'status' => $status]);
                $data['chargeDetail'] = null;
                $detail = filter_var($_GET['cobranca'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($detail !== false && $detail !== null) {
                    try {
                        $found = $service->findCharge($user, (int)$detail);
                        if ($found['kind'] === $kind) {
                            $data['chargeDetail'] = $found;
                        } else {
                            $data['shortcutError'] = 'Este lançamento é de outra página.';
                        }
                    } catch (RegistryNotFound) {
                        $data['shortcutError'] = 'Lançamento não encontrado no seu escopo.';
                    }
                }
                $data['chargeUnits'] = $context === null ? [] : Panel::documentUnits($registry->units($user), $condominiumNames(), $context);
                $data['chargeAccounts'] = self::forContext($registry->references($user, RegistryService::definition('receivables'))['chart_account_id'] ?? [], $context);
                // Origem da multa (ADR 0080): ocorrência ou reserva da unidade, opcional.
                $data['fineOrigins'] = $kind === 'fine' && $context !== null ? (new \Source\Services\Erp\CollectionScreenOptions())->fineOrigins($user, $context) : ['occurrences' => [], 'reservations' => []];
            } else {
                $status = in_array($_GET['situacao'] ?? null, ['active', 'cancelled'], true) ? $_GET['situacao'] : null;
                $data['transferStatus'] = $status;
                $data['transfers'] = (new EntryShortcuts())->listTransfers($user, $context, ['status' => $status]);
                $data['transferAccounts'] = self::forContext($registry->references($user, RegistryService::definition('receivables'))['bank_account_id'] ?? [], $context);
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'entry_shortcut_screen_failed', 'page' => $id]);
            return ['shortcutReady' => false, 'shortcutError' => null];
        }
        $data['shortcutReady'] = true;
        $data['shortcutContext'] = $context;
        return $data;
    }

    /**
     * Opções de referência que valem para o condomínio em contexto (regra pura): as do próprio
     * condomínio e as do padrão da Connect (sem condomínio). Sem contexto, nenhuma: gravar pede um
     * condomínio escolhido na barra superior.
     *
     * @param list<array{id:int, condominium_id:?int, label:string}> $options
     * @return list<array{id:int, condominium_id:?int, label:string}>
     */
    public static function forContext(array $options, ?int $context): array
    {
        if ($context === null) {
            return [];
        }
        return array_values(array_filter($options, static fn (array $o): bool => $o['condominium_id'] === null || $o['condominium_id'] === $context));
    }
}
