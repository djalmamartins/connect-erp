<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de configurações que já tinham serviço e API (ADR 0144, sobre o ADR 0085):
 *  - parâmetros por página (AP-147, AP-151, AP-154, AP-155, AP-157, AP-158, AP-159, AP-166): o valor efetivo e
 *    a origem de cada chave de ErpSettings::CATALOG, com salvar e voltar ao padrão por POST /api/v1/settings;
 *  - saldo inicial por centro de custo (AP-162): GET/POST /api/v1/cost-center-openings;
 *  - previsão orçamentária (AP-163): GET/POST /api/v1/budgets, 12 meses por conta analítica;
 *  - saldo inicial do plano de contas (AP-161, ADR 0146 sobre o 0166): GET/POST /api/v1/chart-account-openings.
 * Ver exige erp.settings.view; gravar, erp.settings.manage (a API confere de novo). Sem condomínio na barra
 * superior, os parâmetros mostram o padrão da Connect, que só muda com acesso a todos os condomínios; saldo
 * inicial e previsão pedem um condomínio. Sem banco ou sem permissão, settingsReady false = demonstração.
 */
final class SettingsScreens
{
    /** Página => tipo da tela. */
    public const PAGES = ['AP-147' => 'params', 'AP-151' => 'params', 'AP-154' => 'params', 'AP-155' => 'params', 'AP-157' => 'params',
        'AP-158' => 'params', 'AP-159' => 'params', 'AP-166' => 'params', 'AP-162' => 'openings', 'AP-163' => 'budget', 'AP-161' => 'chart-openings'];

    public const SOURCES = ['code' => ['Padrão do sistema', 'plain'], 'connect' => ['Padrão da Connect', 'info'], 'condominium' => ['Próprio do condomínio', 'success']];

    public static function template(string $id): string
    {
        return ['params' => 'pages/settings/parameters', 'openings' => 'pages/settings/openings', 'budget' => 'pages/settings/budget', 'chart-openings' => 'pages/settings/chart-openings'][self::PAGES[$id]];
    }

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.settings.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['settingsReady' => false];
        $kind = self::PAGES[$id] ?? null;
        if ($kind === null || Connect::databaseDisabled() || !Access::can('erp.settings.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        $pdo = Connect::getInstance();
        $service = new ErpSettings();
        $data += ['settingsKind' => $kind, 'settingsContext' => $context, 'settingsError' => null, 'settingsNeedsCondo' => false,
            'canManageSettings' => Access::can('erp.settings.manage', $user), 'canEditDefault' => (new CondominiumScope())->hasGlobalAccess($user)];
        try {
            if ($kind === 'params') {
                $list = $service->list($user, $context);
                $group = array_values(array_filter($list['groups'], static fn (array $g): bool => $g['page'] === $id))[0] ?? ['group' => '', 'settings' => []];
                $data += ['settingsGroup' => $group];
            } elseif ($context === null) {
                $data['settingsNeedsCondo'] = true;
            } elseif ($kind === 'chart-openings') {
                $data += ['chartOpenings' => (new ChartOpenings())->list($user, $context), 'openingAccounts' => self::analyticAccounts($pdo, $context)];
            } elseif ($kind === 'openings') {
                $data += ['openings' => $service->openings($user, $context), 'costCenters' => self::costCenters($pdo, $context)];
            } else {
                $year = self::year($_GET['ano'] ?? null, (int)date('Y'));
                $data += ['budgetYear' => $year, 'budget' => $service->budget($user, $context, $year), 'budgetAccounts' => self::analyticAccounts($pdo, $context)];
            }
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            $data['settingsError'] = 'Condomínio não encontrado no seu escopo.';
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'settings_screen_failed', 'page' => $id]);
            return ['settingsReady' => false];
        }
        $data['settingsReady'] = true;
        return $data;
    }

    /** Ano do filtro (regra pura): de 2000 até o ano atual + 5, senão o atual. */
    public static function year(mixed $value, int $current): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => $current + 5]]);
        return $year === false || $year === null ? $current : (int)$year;
    }

    /**
     * Campo do formulário de um parâmetro (regra pura): tipo do input HTML e atributos, pelo tipo do catálogo.
     *
     * @return array{tag:string, type:string, attrs:array<string, string>, value:string}
     */
    public static function field(array $setting): array
    {
        $value = $setting['value'];
        $attrs = [];
        if ($setting['min'] !== null) {
            $attrs['min'] = (string)$setting['min'];
        }
        if ($setting['max'] !== null) {
            $attrs[$setting['type'] === 'text' ? 'maxlength' : 'max'] = (string)$setting['max'];
        }
        if ($setting['type'] === 'text' && $setting['min'] !== null) {
            unset($attrs['min']);
            $attrs['minlength'] = (string)$setting['min'];
        }
        return match ($setting['type']) {
            'bool' => ['tag' => 'select', 'type' => 'bool', 'attrs' => [], 'value' => $value ? '1' : '0'],
            'enum' => ['tag' => 'select', 'type' => 'enum', 'attrs' => [], 'value' => (string)$value],
            'int' => ['tag' => 'input', 'type' => 'number', 'attrs' => $attrs + ['step' => '1'], 'value' => (string)(int)$value],
            'decimal' => ['tag' => 'input', 'type' => 'number', 'attrs' => $attrs + ['step' => 'any'], 'value' => (string)$value],
            'money' => ['tag' => 'input', 'type' => 'text', 'attrs' => ['inputmode' => 'decimal', 'pattern' => '^\d{1,9}([.,]\d{1,2})?$'], 'value' => number_format(((int)$value) / 100, 2, ',', '')],
            'email' => ['tag' => 'input', 'type' => 'email', 'attrs' => ['maxlength' => '190'], 'value' => (string)$value],
            default => ['tag' => 'input', 'type' => 'text', 'attrs' => $attrs, 'value' => (string)$value],
        };
    }

    /** Valor de um parâmetro para mostrar (regra pura). */
    public static function display(array $setting): string
    {
        $value = $setting['value'];
        return match ($setting['type']) {
            'bool' => $value ? 'Sim' : 'Não',
            'money' => 'R$ ' . number_format(((int)$value) / 100, 2, ',', '.'),
            'enum' => (string)($setting['options'][$value] ?? $value),
            default => (string)$value === '' ? '—' : (string)$value,
        };
    }

    /** @return list<array{id:int, label:string}> centros de custo ativos do padrão e do condomínio */
    private static function costCenters(PDO $pdo, int $condominiumId): array
    {
        $stmt = $pdo->prepare("SELECT id, code, name FROM erp_cost_centers WHERE deleted_at IS NULL AND status = 'active' AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY code, name");
        $stmt->execute(['c' => $condominiumId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => trim($r['code'] . ' ' . $r['name'])], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int, label:string}> contas analíticas ativas do padrão e do condomínio */
    private static function analyticAccounts(PDO $pdo, int $condominiumId): array
    {
        $stmt = $pdo->prepare("SELECT id, code, name, nature FROM erp_chart_accounts WHERE deleted_at IS NULL AND status = 'active' AND kind = 'analitica'
            AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY nature = 'despesa', code");
        $stmt->execute(['c' => $condominiumId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => trim($r['code'] . ' ' . $r['name']) . ' (' . ($r['nature'] === 'receita' ? 'receita' : 'despesa') . ')'],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
