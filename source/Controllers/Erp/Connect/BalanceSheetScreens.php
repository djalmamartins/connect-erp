<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Balanço Patrimonial (AP-081; ADR 0148, sobre o ADR 0167), lido no servidor pelo mesmo serviço da API
 * GET /api/v1/reports/balance-sheet: ativo, passivo e patrimônio líquido de um condomínio numa data, com as
 * contas bancárias do Disponível, o vencido de receber e pagar, a diferença e os avisos. Somente leitura; o CSV
 * vem da própria API. Exige erp.entries.view e erp.chart.view, como a API. Sem banco ou sem permissão,
 * balanceReady false = a tela segue com a demonstração.
 */
final class BalanceSheetScreens
{
    public const PAGES = ['AP-081' => 'pages/reports/balance-sheet'];

    /** Grupos na ordem da tela: chave do relatório => [título, chave do total]. */
    public const GROUPS = ['assets' => ['Ativo', 'assets_cents'], 'liabilities' => ['Passivo', 'liabilities_cents'], 'equity' => ['Patrimônio líquido', 'equity_cents']];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.entries.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            return ['balanceReady' => false];
        }
        $context = CondominiumContext::id();
        $date = self::date($_GET['data'] ?? null, date('Y-m-d'));
        $data = ['balanceReady' => false, 'balanceDate' => $date, 'balanceNeedsCondo' => $context === null, 'balanceError' => null, 'balance' => null];
        try {
            if ($context !== null) {
                try {
                    $data['balance'] = (new BalanceSheet())->build($user, $context, $date);
                } catch (InvalidArgumentException $error) {
                    $data['balanceError'] = $error->getMessage();
                } catch (CondominiumAccessDenied | RegistryNotFound) {
                    $data['balanceError'] = 'Condomínio não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'balance_sheet_screen_failed', 'page' => $id]);
            return ['balanceReady' => false];
        }
        $data['balanceReady'] = true;
        return $data;
    }

    /** Data da tela (regra pura): AAAA-MM-DD válida, de 2000 em diante e não futura; senão hoje. */
    public static function date(mixed $value, string $today): string
    {
        if (!is_string($value) || $value > $today) {
            return $today;
        }
        try {
            return BalanceSheet::date($value);
        } catch (InvalidArgumentException) {
            return $today;
        }
    }

    /**
     * Linhas da tabela de um grupo (regra pura): a árvore do relatório e, logo abaixo do Disponível, uma linha por
     * conta bancária (nível + 1, marcada como detalhe).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $group): array
    {
        $out = [];
        foreach ($group['rows'] ?? [] as $row) {
            $out[] = $row;
            foreach ($row['lines'] ?? [] as $line) {
                $suffix = ['poupanca' => ' (poupança)', 'aplicacao' => ' (aplicação)'][$line['kind'] ?? ''] ?? '';   // ADR 0170
                $out[] = ['code' => '', 'name' => (string)$line['name'] . $suffix, 'level' => (int)$row['level'] + 1, 'amount_cents' => (int)$line['amount_cents'], 'detail' => true];
            }
        }
        return $out;
    }
}
