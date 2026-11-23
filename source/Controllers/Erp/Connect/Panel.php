<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Services\Support\SacDesk;
use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\DemoData;
use Source\Services\Erp\ErpNav;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;
use Source\Support\Erp\FieldSpec;

/**
 * ERP v1 — moldura navegável: dashboard, visão geral de cada módulo e as páginas do
 * catálogo validado (docs/ERP_V1_ROUTE_MATRIX.md).
 *
 * Toda rota exige sessão completa (Auth só grava authUser depois do 2FA) e erp.access.
 * Permissões por módulo (erp.<módulo>.view) ainda não existem no banco: estão listadas
 * como pendência em docs/ERP_V1_FRONTEND_STATUS.md e não são simuladas aqui.
 */
final class Panel extends Controller
{
    private const THEME = __DIR__ . '/../../../../container/erp/connect/v1/';

    /** Páginas com permissão própria já cadastrada (migration 20261107_0003). As demais exigem erp.access. */
    public const PAGE_PERMISSIONS = ['AP-004' => 'erp.condominiums.view', 'CN-001' => 'erp.inspections.view', 'AP-114' => 'erp.reservations.view', 'CN-005' => 'erp.notifications.view', 'AP-171' => 'erp.settings.view',
        // Lançamentos avulsos e recibos (ADR 0057 e 0073).
        'AP-024' => 'erp.entries.view', 'AP-025' => 'erp.entries.view', 'AP-026' => 'erp.entries.view', 'AP-027' => 'erp.invoices.view', 'AP-028' => 'erp.invoices.view',
        'AP-029' => 'erp.entries.view', 'AP-040' => 'erp.unit_documents.view',
        // Importações (ADR 0058 e 0074).
        'AP-140' => 'erp.imports.view', 'AP-142' => 'erp.imports.view', 'AP-143' => 'erp.imports.view', 'AP-144' => 'erp.imports.view',
        'AP-145' => 'erp.imports.view', 'AP-146' => 'erp.imports.view', 'AP-044' => 'erp.imports.view', 'AP-141' => 'erp.imports.view',
        // Comunicação (ADR 0059 e 0075).
        'AP-111' => 'erp.announcements.view', 'AP-137' => 'erp.announcements.view', 'AP-138' => 'erp.announcements.view', 'AP-139' => 'erp.announcements.view',
        // Cobrança e multas (ADR 0080 e 0076).
        'AP-116' => 'erp.collections.view', 'AP-117' => 'erp.collections.view', 'AP-039' => 'erp.collections.view',
        // Relatórios de cobrança (ADR 0081 e 0077); a visão por morador exige também erp.residents.view (conferido nos dados).
        'AP-047' => 'erp.agreements.view', 'AP-048' => 'erp.agreements.view', 'AP-057' => 'erp.agreements.view', 'AP-058' => 'erp.agreements.view',
        'AP-059' => 'erp.agreements.view', 'AP-060' => 'erp.agreements.view', 'AP-061' => 'erp.agreements.view', 'AP-071' => 'erp.entries.view', 'AP-072' => 'erp.entries.view',
        // Gráficos (ADR 0083 e 0078); as permissões extras (plano de contas, contas bancárias) são conferidas nos dados.
        'AP-102' => 'erp.entries.view', 'AP-103' => 'erp.entries.view', 'AP-104' => 'erp.meter_readings.view', 'AP-105' => 'erp.meter_readings.view',
        'AP-106' => 'erp.entries.view', 'AP-107' => 'erp.agreements.view', 'AP-108' => 'erp.entries.view', 'AP-109' => 'erp.reservations.view',
        // Dados pessoais (LGPD, ADR 0332).
        'CN-009' => 'erp.privacy.export',
        // Propostas do site (ADR 0644): a mesma permissão do Studio > Gestão > Propostas.
        'CN-011' => 'proposals.manage'];

    /** Chave de sessão do condomínio em contexto (0/ausente = todos os do escopo). */
    public const CONTEXT_KEY = CondominiumContext::SESSION_KEY;

    /** @return array<string, string> PAGE_PERMISSIONS mais as permissões dos cadastros (migration 20261108_0202). */
    public static function pagePermissions(): array
    {
        return self::PAGE_PERMISSIONS + ReportScreens::pagePermissions() + SettingsScreens::pagePermissions() + AssemblyScreens::pagePermissions() + DeliveryScreens::pagePermissions() + AccountabilityScreens::pagePermissions() + BalanceSheetScreens::pagePermissions() + CashBookScreens::pagePermissions() + PatrimonialEntryScreens::pagePermissions() + AccessScreens::pagePermissions() + AdminScreens::pagePermissions() + Definitions::pagePermissions();
    }

    /** @var array{groups: array<string, array<string, string>>, pages: array<string, array<string, string>>} */
    private array $catalog;

    /** @var list<array{id:int,name:string}>|null cache por requisição; o smoke injeta uma lista fixa */
    private ?array $condominiumOptions = null;

    public function __construct()
    {
        parent::__construct(self::THEME);
        $this->catalog = require self::THEME . 'components/layout/catalog.php';
    }

    /** GET /erp */
    public function root(): void
    {
        redirect(erp_url(Auth::user() ? '/dashboard' : '/login'));
    }

    /** GET /erp/dashboard (AP-001 reinterpretada: dashboard da administradora). */
    public function dashboard(): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        echo $this->view->render('pages/dashboard/index', $this->layoutData($user, 'AP-001', [
            'summary' => $this->summary($user, CondominiumContext::id()),
            'condoTotal' => count($this->condominiumOptions($user)),
            'operational' => $this->operationalSummary($user),
        ] + $this->dashboardData($user)));
    }

    /**
     * Blocos do dashboard que eram demonstração (ADR 0157), agora do MariaDB no condomínio da barra
     * superior: $finance (receitas e despesas, inadimplência por atraso e quadro por condomínio, com
     * erp.entries.view), $agenda (próximos 30 dias) e $activities (com erp.audit.view). Cada um é null
     * sem permissão, sem banco ou em falha (a tela mostra o aviso daquele bloco, sem números inventados).
     *
     * @return array{finance: ?array, agenda: ?array, activities: ?array, competence: string}
     */
    private function dashboardData(User $user): array
    {
        $competence = \Source\Services\Erp\FinancialDashboard::competenceOrCurrent($_GET['competencia'] ?? null);
        $data = ['finance' => null, 'agenda' => null, 'activities' => null, 'competence' => $competence];
        if (Connect::databaseDisabled()) {
            return $data;
        }
        $service = new \Source\Services\Erp\FinancialDashboard();
        $context = CondominiumContext::id();
        $modules = \Source\Services\Erp\FinancialDashboard::agendaModules($user);
        $blocks = [
            'finance' => [Access::can('erp.entries.view', $user), fn () => $service->finance($user, $context, $competence)],
            'agenda' => [in_array(true, $modules, true), fn () => $service->agenda($user, $context, $modules)],
            'activities' => [Access::can('erp.audit.view', $user), fn () => $service->activities($user, $context)],
        ];
        foreach ($blocks as $key => [$allowed, $load]) {
            if (!$allowed) {
                continue;
            }
            try {
                $data[$key] = $load();
            } catch (\Throwable $error) {
                \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'dashboard_' . $key . '_failed']);
            }
        }
        return $data;
    }

    /**
     * Cards e alertas reais do dashboard (ADR 0053 e 0070), pelo mesmo serviço de GET /api/v1/dashboard,
     * no condomínio da barra superior. null (sem banco ou falha) = a tela segue com a demonstração.
     */
    private function operationalSummary(User $user): ?array
    {
        // Mesma regra de GET /api/v1/dashboard (ADR 0111): sem erp.units.view, sem contagens operacionais.
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return null;
        }
        try {
            return (new \Source\Services\Erp\OperationalDashboard())->summary($user, CondominiumContext::id());
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'dashboard_operational_failed']);
            return null;
        }
    }

    /**
     * Indicadores do dashboard a partir dos cards do serviço (regra pura): a receber vencido, a pagar
     * em 7 dias, manutenções atrasadas e ocorrências abertas, no formato do componente cards/kpi.
     *
     * @param array<string, int> $cards
     * @return list<array{key:string,label:string,value:string,meta:string,icon:string,tone:string,trend:null,href:string}>
     */
    public static function dashboardKpis(array $cards): array
    {
        $brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
        $c = static fn (string $key): int => (int)($cards[$key] ?? 0);
        return [
            ['key' => 'receivables_overdue', 'label' => 'A receber vencido', 'value' => $brl($c('receivables_overdue_cents')), 'meta' => 'Vence em 7 dias: ' . $brl($c('receivables_week_cents')),
                'icon' => 'wallet', 'tone' => 'hero', 'trend' => null, 'href' => '/financeiro/contas-a-receber'],
            ['key' => 'payables_week', 'label' => 'A pagar em 7 dias', 'value' => $brl($c('payables_week_cents')), 'meta' => 'Contas a pagar em aberto',
                'icon' => 'receipt', 'tone' => $c('payables_week_cents') > 0 ? 'warning' : '', 'trend' => null, 'href' => '/financeiro/contas-a-pagar'],
            ['key' => 'maintenances_late', 'label' => 'Manutenções atrasadas', 'value' => (string)$c('maintenances_late'), 'meta' => $plural($c('maintenances_week'), 'prevista', 'previstas') . ' para os próximos 7 dias',
                'icon' => 'calendar', 'tone' => $c('maintenances_late') > 0 ? 'danger' : '', 'trend' => null, 'href' => '/operacional/manutencoes'],
            ['key' => 'occurrences_open', 'label' => 'Ocorrências abertas', 'value' => (string)$c('occurrences_open'), 'meta' => $plural($c('reservations_pending'), 'reserva aguardando', 'reservas aguardando') . ' aprovação',
                'icon' => 'clipboard', 'tone' => '', 'trend' => null, 'href' => '/operacional/ocorrencias'],
        ];
    }

    /**
     * Resumo com dados reais para o dashboard: quantos registros cada cadastro tem no
     * condomínio da barra superior (ou em todos). Só cadastros que o usuário pode ver; os do
     * financeiro ficam de fora até o dashboard financeiro ter dados reais.
     *
     * @return list<array{label:string, count:int, href:string, icon:string}>
     */
    private function summary(User $user, ?int $condominiumId): array
    {
        $service = new RegistryService();
        $items = [];
        foreach (Definitions::all() as $slug => $definition) {
            if (!in_array($definition['group'] ?? 'cadastros', ['cadastros', 'condominios'], true) || ($condominiumId !== null && $definition['scope'] === 'none')) {
                continue;
            }
            if (!Access::can($definition['permission'] . '.view', $user)) {
                continue;
            }
            try {
                $count = $service->count($user, $slug, $condominiumId);
            } catch (\Throwable $error) {
                \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'dashboard_summary_failed', 'registry' => $slug]);
                continue;
            }
            $items[] = ['label' => $definition['label'], 'count' => $count, 'href' => $definition['route'], 'icon' => ($definition['group'] ?? 'cadastros') === 'condominios' ? 'building' : 'user'];
        }
        return $items;
    }

    /*
     * Atalhos mantidos para quem já chama Panel:: (telas, testes e outras *Screens). Os dados e as regras
     * ficam em FinanceScreens, UnitScreens e OperationScreens.
     */
    public const REPORT_PAGES = FinanceScreens::REPORT_PAGES;
    public const UNIT_DOCUMENT_PAGES = UnitScreens::UNIT_DOCUMENT_PAGES;
    public const RECEIPT_PAGE = UnitScreens::RECEIPT_PAGE;
    public const METER_KINDS = UnitScreens::METER_KINDS;

    /** @see FinanceScreens::flattenParcels() */
    public static function flattenParcels(array $agreements, string $today): array
    {
        return FinanceScreens::flattenParcels($agreements, $today);
    }

    /** @see UnitScreens::contextUnits() */
    public static function contextUnits(array $units, ?int $context): array
    {
        return UnitScreens::contextUnits($units, $context);
    }

    /** @see UnitScreens::documentUnits() */
    public static function documentUnits(array $units, array $names, ?int $context): array
    {
        return UnitScreens::documentUnits($units, $names, $context);
    }

    /** @see UnitScreens::readingSheet() */
    public static function readingSheet(array $meters, array $unitLabels, array $readings, string $competence): array
    {
        return UnitScreens::readingSheet($meters, $unitLabels, $readings, $competence);
    }

    /** @see OperationScreens::fileSize() */
    public static function fileSize(int $bytes): string
    {
        return OperationScreens::fileSize($bytes);
    }

    /**
     * Telas com classe própria, na ordem em que os dados são somados (a primeira chave vence). Cada uma
     * lista as páginas em PAGES (ImportScreens, em pages()) e monta os dados em data($user, $id).
     */
    private const SCREENS = [FinanceScreens::class, OperationScreens::class, UnitScreens::class, ImportScreens::class, CommunicationScreens::class,
        CollectionScreens::class, CollectionReportScreens::class, ChartScreens::class, ReportScreens::class, SettingsScreens::class, AssemblyScreens::class,
        DeliveryScreens::class, AccountabilityScreens::class, PatrimonialEntryScreens::class, BalanceSheetScreens::class, CashBookScreens::class,
        AccessScreens::class, AdminScreens::class, EntryShortcutScreens::class];

    /** @return array<string, mixed> os dados reais das telas que têm $id, somados na ordem de SCREENS */
    private function screenData(User $user, string $id): array
    {
        $options = fn (): array => $this->condominiumOptions($user);
        $data = [];
        foreach (self::SCREENS as $class) {
            $pages = method_exists($class, 'pages') ? $class::pages() : $class::PAGES;
            if (!isset($pages[$id])) {
                continue;
            }
            $data += match ($class) {
                FinanceScreens::class => (new FinanceScreens())->data($user, $id),
                UnitScreens::class => (new UnitScreens())->data($user, $id, $options),
                OperationScreens::class => (new OperationScreens())->data($user, $id, $options),
                EntryShortcutScreens::class => (new EntryShortcutScreens())->data($user, $id, fn (): array => array_column($options(), 'name', 'id')),
                default => (new $class())->data($user, $id),
            };
        }
        return $data;
    }


    /** GET /erp/{módulo}: visão geral do módulo, com todas as páginas do grupo. */
    public function hub(string $group): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        if (!isset($this->catalog['groups'][$group])) {
            $this->state($user, 404, 'Página não encontrada', 'O endereço não corresponde a nenhum módulo do ERP.');
            return;
        }
        $pages = array_filter($this->catalog['pages'], static fn (array $page): bool => $page['group'] === $group);
        echo $this->view->render('pages/system/hub', $this->layoutData($user, null, [
            'hubGroup' => $group,
            'hubPages' => $pages,
        ], $this->catalog['groups'][$group]['label']));
    }

    /** GET de cada rota do catálogo. */
    public function page(string $id): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $page = $this->catalog['pages'][$id] ?? null;
        if ($page === null) {
            $this->state($user, 404, 'Página não encontrada', 'O endereço não corresponde a nenhuma página do ERP.');
            return;
        }
        echo $this->view->render(...$this->pageView($user, $id));
    }

    /**
     * Template e dados de uma página do catálogo, pelo tipo de interface. Também usado
     * pelo smoke de renderização (tests/erp/render-smoke.php).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function pageView(User $user, string $id): array
    {
        $specs = require self::THEME . 'components/layout/page-specs.php';
        $spec = $specs[$id] ?? null;
        if ($spec === null) {
            return ['pages/system/pending', $this->layoutData($user, $id)];
        }
        $kind = $spec['kind'];
        $columns = FieldSpec::parseAll($spec['columns'] ?? [], 'c');
        if ($kind === 'import') {
            $columns = FieldSpec::parseAll(array_map(static fn (string $c): string => $c . (str_contains($c, 'Situação') ? ':status(Pronta para importar|Com erro|Duplicada)' : (preg_match('/Valor/', $c) ? ':money' : (preg_match('/Data|Emissão|Vencimento|Pago em/', $c) ? ':date' : ':text'))), $spec['columns']), 'c');
        }
        $data = [
            'spec' => $spec,
            'columns' => $columns,
            'fields' => FieldSpec::parseAll($spec['fields'] ?? [], strtolower($id)),
            'rows' => [],
            'chart' => [],
            'vocabulary' => DemoData::get('vocabulary'),
        ];
        if (isset($spec['sections'])) {
            foreach ($spec['sections'] as $i => $section) {
                $data['spec']['sections'][$i]['fields'] = FieldSpec::parseAll($section['fields'], strtolower($id) . '-' . $i);
            }
        }
        if (in_array($kind, ['list', 'report', 'import'], true)) {
            $data['rows'] = DemoData::rows($id, $spec['columns'] ?? [], $kind === 'import' ? 6 : ($kind === 'report' ? 18 : 23));
            if ($kind === 'import') {
                $data['rows'] = DemoData::rows($id, array_map(static fn (array $c): string => $c['label'] . ':' . $c['type'] . ($c['options'] ? '(' . implode('|', $c['options']) . ')' : ''), $columns), 6);
            }
        }
        if (in_array($kind, ['chart', 'dashboard'], true) && DemoData::enabled()) {
            $series = $spec['series'] ?? ['Receitas', 'Despesas'];
            mt_srand(crc32($id));
            $data['chart'] = [
                'labels' => ['Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov'],
                'series' => array_map(static fn (string $name): array => ['name' => $name, 'values' => array_map(static fn (): int => mt_rand(40, 100), range(1, 6))], $series),
                'unit' => str_contains($series[0], '%') ? '%' : (str_contains($series[0], 'm³') ? 'm³' : (count($series) > 1 ? 'R$ mil' : 'quantidade')),
            ];
            mt_srand();
        }
        if ($kind === 'dashboard') {
            $cols = $id === 'AP-003' ? ['Vencimento:date', 'Descrição:text', 'Valor:money', 'Situação:status(Aberto|Pago|Vencido)'] : ['Vencimento:date', 'Fornecedor:text', 'Valor:money', 'Situação:status(A vencer|Vencida|Agendada)'];
            $data['columns'] = FieldSpec::parseAll($cols, 'c');
            $data['rows'] = DemoData::rows($id, $cols, 6);
        }
        $data += $this->screenData($user, $id);
        return [self::ownTemplate($id, $data) ?? 'pages/modules/' . $kind, $this->layoutData($user, $id, $data)];
    }

    /**
     * Páginas com tela própria quando há dados reais: AP-032 faturas (lista, itens por unidade,
     * emitir e cancelar), AP-035 boletos, AP-036 importar retorno, AP-038 retornos com
     * divergência, AP-079 balancete e AP-051 demonstrativo. Sem dados (sem permissão ou sem banco), null = segue a demonstração.
     */
    public static function ownTemplate(string $id, array $data): ?string
    {
        return match (true) {
            $id === 'AP-032' && ($data['invoices'] ?? null) !== null => 'pages/finance/invoices',
            $id === 'AP-033' && ($data['delinquency'] ?? null) !== null => 'pages/finance/agreements',
            $id === 'AP-165' && ($data['billingRule'] ?? null) !== null => 'pages/finance/billing-rules',
            $id === 'AP-034' && ($data['agreementParcels'] ?? null) !== null => 'pages/finance/agreement-parcels',
            $id === 'AP-035' && ($data['bankCharges'] ?? null) !== null => 'pages/finance/bank-charges',
            $id === 'AP-036' && ($data['bankReady'] ?? false) => 'pages/finance/bank-return-import',
            $id === 'AP-037' && ($data['bankRemittances'] ?? null) !== null => 'pages/finance/bank-remittances',
            $id === 'AP-038' && ($data['bankDivergences'] ?? null) !== null => 'pages/finance/bank-divergences',
            (isset(self::UNIT_DOCUMENT_PAGES[$id]) || $id === self::RECEIPT_PAGE) && ($data['unitDocuments'] ?? null) !== null => 'pages/documents/unit-document',
            isset(EntryShortcutScreens::PAGES[$id]) && ($data['shortcutReady'] ?? false) => EntryShortcutScreens::PAGES[$id],
            isset(ImportScreens::pages()[$id]) && ($data['importReady'] ?? false) => 'pages/imports/import',
            isset(CommunicationScreens::PAGES[$id]) && ($data['commReady'] ?? false) => CommunicationScreens::PAGES[$id],
            isset(CollectionScreens::PAGES[$id]) && ($data['collReady'] ?? false) => CollectionScreens::PAGES[$id],
            isset(CollectionReportScreens::PAGES[$id]) && ($data['cReportReady'] ?? false) => 'pages/reports/collection-report',
            isset(ChartScreens::PAGES[$id]) && ($data['chartReady'] ?? false) => 'pages/reports/chart',
            isset(ReportScreens::PAGES[$id]) && ($data['reportReady'] ?? false) => 'pages/reports/report',
            isset(SettingsScreens::PAGES[$id]) && ($data['settingsReady'] ?? false) => SettingsScreens::template($id),
            isset(AssemblyScreens::PAGES[$id]) && ($data['assemblyReady'] ?? false) => 'pages/assemblies/assembly',
            isset(DeliveryScreens::PAGES[$id]) && ($data['deliveryReady'] ?? false) => DeliveryScreens::PAGES[$id],
            isset(AccountabilityScreens::PAGES[$id]) && ($data['accountabilityReady'] ?? false) => AccountabilityScreens::PAGES[$id],
            isset(BalanceSheetScreens::PAGES[$id]) && ($data['balanceReady'] ?? false) => BalanceSheetScreens::PAGES[$id],
            isset(CashBookScreens::PAGES[$id]) && ($data['cashReady'] ?? false) => CashBookScreens::PAGES[$id],
            isset(PatrimonialEntryScreens::PAGES[$id]) && ($data['patrimonialReady'] ?? false) => PatrimonialEntryScreens::PAGES[$id],
            isset(AccessScreens::PAGES[$id]) && ($data['accessReady'] ?? false) => 'pages/settings/access',
            isset(AdminScreens::PAGES[$id]) && ($data['adminReady'] ?? false) => 'pages/settings/admin',
            $id === 'AP-042' && ($data['meterSheet'] ?? null) !== null => 'pages/operational/meter-readings',
            $id === 'AP-122' && ($data['diskFiles'] ?? null) !== null => 'pages/documents/virtual-disk',
            $id === 'AP-114' && ($data['pendingReservations'] ?? null) !== null => 'pages/operational/pending-reservations',
            $id === 'AP-171' && ($data['integrations'] ?? null) !== null => 'pages/settings/integrations',
            $id === 'AP-003' && ($data['unitPanelReady'] ?? false) => 'pages/condominiums/unit-panel',
            $id === 'AP-167' && ($data['measuresReady'] ?? false) => 'pages/condominiums/unit-measures',
            // Relatórios (ADR 0037): com dados ou com erro de período; sem permissão/banco, a demonstração.
            $id === 'AP-079' && (($data['trialBalance'] ?? null) !== null || ($data['reportError'] ?? null) !== null) => 'pages/reports/trial-balance',
            $id === 'AP-051' && (($data['statement'] ?? null) !== null || ($data['reportError'] ?? null) !== null) => 'pages/reports/statement',
            default => null,
        };
    }

    /**
     * Erro de rota no host do ERP: sem sessão vai ao login; com sessão, estado de erro na
     * moldura. Não redireciona para /ops (rota do site, que não existe neste host).
     */
    public function routeError(int $code): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        if (!Access::can('erp.access', $user)) {
            $this->state($user, 403, 'Acesso negado', 'Sua conta não tem permissão para acessar o ERP. Fale com o administrador da Connect.', false);
            return;
        }
        $code = in_array($code, [404, 405], true) ? $code : 500;
        $this->state($user, $code, $code === 500 ? 'Algo deu errado' : 'Página não encontrada', $code === 500 ? 'Não foi possível concluir a solicitação. Tente de novo em instantes.' : 'O endereço não corresponde a nenhuma página do ERP.');
    }

    /**
     * POST /preferencias/menu — troca entre o menu novo e o atual (ADR 0398). Guarda a escolha num cookie do
     * navegador por um ano e volta para a mesma tela. Só aceita os modos conhecidos.
     */
    public function setMenu(?array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $back = (string)($data['back'] ?? '');
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') && !preg_match('/[\x00-\x20\\\\?#]/', $back) ? $back : '/dashboard';
        if (!csrf_verify($data ?? [])) {
            $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->flash();
            redirect(erp_url($back));
            return;
        }
        $mode = (string)($data['menu'] ?? '');
        if (in_array($mode, ErpNav::MODES, true) && !headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(ErpNav::COOKIE, $mode, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        }
        redirect(erp_url($back));
    }

    /**
     * POST /contexto/condominio — troca o condomínio em contexto na navbar (ADR 0030). Só
     * aceita um condomínio do escopo do usuário (CondominiumScope); 0 volta para "todos".
     * Redireciona para a mesma tela com o slug do condomínio escolhido na URL. Em telas de
     * detalhe (/…/{id}) volta para a lista, porque o registro é de outro condomínio.
     */
    public function setCondominium(?array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $back = (string)($data['back'] ?? '');
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') && !preg_match('/[\x00-\x20\\\\?#]/', $back) ? $back : '/dashboard';
        if (!CondominiumContext::aware($back)) {
            $back = '/dashboard';
        }
        // Detalhe, edição ou cadastro novo: volta para a lista da mesma tela.
        $back = (string)preg_replace('~/(?:\d+|novo|nova)(?:/.*)?$~', '', $back) ?: '/dashboard';
        if (!csrf_verify($data ?? [])) {
            $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->flash();
            redirect(erp_url($back));
            return;
        }
        $id = (int)(filter_var($data['condominium'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0);
        $session = new Session();
        CondominiumContext::use($this->condominiumOptions($user), CondominiumContext::id());
        if ($id === 0) {
            $session->unset(CondominiumContext::SESSION_KEY);
            redirect(erp_context_url($back, null));
            return;
        }
        $target = CondominiumContext::option($id);
        if ($target === null) {
            // Fora do escopo: não revela se o condomínio existe.
            $this->message->warning('Condomínio indisponível para a sua conta.')->flash();
            redirect(erp_url($back));
            return;
        }
        $session->set(CondominiumContext::SESSION_KEY, $target['id']);
        redirect(erp_context_url($back, $target));
    }

    /** @return list<array{id:int,name:string}> */
    private function condominiumOptions(User $user): array
    {
        return $this->condominiumOptions ??= CondominiumContext::scopeOptions($user);
    }

    /** GET /erp/seguranca/codigos — códigos de recuperação mostrados uma única vez após ativar o 2FA. */
    public function recoveryCodes(): void
    {
        // Os códigos ficam na sessão e aparecem uma única vez na tela de segurança.
        redirect(erp_url('/seguranca'));
    }

    /** Renderiza uma página do ERP na moldura única (usado por outros controllers do ERP). */
    public function render(string $template, User $user, string $title, array $data = []): void
    {
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render($template, $this->layoutData($user, null, $data, $title));
    }

    private function guard(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            $this->state($user, 403, 'Acesso negado', 'Sua conta não tem permissão para acessar o ERP. Fale com o administrador da Connect.', false);
            return null;
        }
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        return $user;
    }

    private function state(User $user, int $code, string $title, string $message, bool $withNavigation = true): void
    {
        http_response_code($code);
        echo $this->view->render('pages/system/state', $this->layoutData($user, null, [
            'stateCode' => $code,
            'stateTitle' => $title,
            'stateMessage' => $message,
            'withNavigation' => $withNavigation,
        ], $title));
    }

    /**
     * Dados compartilhados por default.php e pelos componentes.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function layoutData(User $user, ?string $pageId, array $extra = [], ?string $title = null): array
    {
        $page = $pageId !== null ? ($this->catalog['pages'][$pageId] ?? null) : null;
        $group = $page['group'] ?? ($extra['hubGroup'] ?? null);
        $title ??= $page['name'] ?? 'Connect ERP';
        // Menu lateral (ADR 0398): novo ou atual, escolhido pela pessoa; o endereço da página não muda.
        $navMode = ErpNav::mode();
        $nav = ErpNav::build($this->catalog, $navMode);
        $navGroup = ErpNav::groupOf($nav, $pageId) ?? (isset($nav[(string)$group]) ? $group : null);
        $icons = require self::THEME . 'components/layout/icons.php';
        $dashboard = DemoData::get('dashboard');
        (new SacDesk())->touch($user); // balão do SAC (ADR 0631): quem atende e está no ERP conta como online

        return array_merge([
            'head' => $this->seo->render($title . ' · Connect ERP', 'ERP da administradora Connect.', erp_url(), theme('/assets/images/favicon.png'), false),
            'title' => $title,
            'user' => $user,
            'catalog' => $this->catalog,
            'currentPageId' => $pageId,
            'currentPage' => $page,
            'currentGroup' => $group,
            'nav' => $nav,
            'navMode' => $navMode,
            'navGroup' => $navGroup,
            'navSection' => ErpNav::sectionOf($nav, $pageId),
            'breadcrumbs' => $this->breadcrumbs($page, $group, $title, $nav, $pageId),
            'demo' => DemoData::enabled(),
            // Sino (ADR 0142): as notificações reais da pessoa, as mesmas do Studio e do Help Desk.
            // Sem banco (smoke de renderização) cai nas de demonstração, quando ligadas.
            ...$this->bell($user, $dashboard['notifications'] ?? []),
            'supportMenu' => $this->supportMenu($user, $pageId),
            // Selos do menu lateral (ADR 0644): propostas novas do site, para quem pode abrir a tela.
            'navBadges' => $this->navBadges($user),
            'withNavigation' => true,
            // Conferência de 10/11/2026: a linha "Ficha: docs/specs/..." (rastreio da spec) é de quem
            // desenvolve; para a equipe da administradora fica escondida (classe erp-body--trace).
            'showTrace' => (int)($user->level ?? 0) >= 5,
            'condoOptions' => CondominiumContext::booted() ? CondominiumContext::options() : CondominiumContext::withSlugs($this->condominiumOptions($user)),
            'condoCurrent' => CondominiumContext::current(),
            // Guia de cadastro do condomínio novo (ADR 0151): só com o condomínio dele na barra superior.
            'setupGuide' => $this->setupGuide($user),
            'anchor' => static fn (string $text): string => self::anchor($text),
            // Esconder o item é conveniência: a rota valida a mesma permissão no servidor.
            'canSee' => static fn (string $pageId): bool => !isset(self::pagePermissions()[$pageId]) || $user->can(self::pagePermissions()[$pageId]),
            'icon' => static function (string $name, string $class = '') use ($icons): string {
                $paths = $icons[$name] ?? $icons['info'];
                return '<svg class="erp-icon ' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
            },
        ], $extra);
    }

    /**
     * Passos do cadastro do condomínio cujo guia está aberto na sessão, quando ele é o da barra
     * superior. Fora disso (ou sem banco, no smoke de renderização) não há guia.
     *
     * @return array{condominium:array{id:int,name:string,slug:string},steps:list<array>,current:?int}|null
     */
    private function setupGuide(User $user): ?array
    {
        $current = CondominiumContext::current();
        if ($current === null || session_status() !== PHP_SESSION_ACTIVE
            || (int)($_SESSION[\Source\Services\Erp\CondominiumSetup::GUIDE_KEY] ?? 0) !== $current['id']) {
            return null;
        }
        try {
            $steps = (new \Source\Services\Erp\CondominiumSetup())->steps($user, $current['id']);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'condominium_setup_failed', 'condominium_id' => $current['id']]);
            return null;
        }
        return [
            'condominium' => $current,
            'steps' => $steps,
            'current' => \Source\Services\Erp\CondominiumSetup::currentIndex($steps, CondominiumContext::relativePath()),
        ];
    }

    /**
     * Números ao lado de itens do menu lateral (ADR 0644). Hoje só as propostas novas do site (CN-011), para quem
     * tem proposals.manage. Sem banco (smoke de renderização) não há selo.
     *
     * @return array<string, int> id da página => quantidade
     */
    private function navBadges(User $user): array
    {
        if (Connect::databaseDisabled() || !$user->can('proposals.manage')) {
            return [];
        }
        try {
            $new = (new \Source\Services\Proposals\ProposalDesk())->newCount();
        } catch (\Throwable) {
            return [];
        }
        return $new > 0 ? ['CN-011' => $new] : [];
    }

    /**
     * @param list<array{title:string,text:string,href:string}> $demo
     * @return array{notifications:list<array>,notificationCount:int,notificationsReal:bool}
     */
    private function bell(User $user, array $demo): array
    {
        try {
            $feed = new \Source\Services\Support\NotificationFeed();
            $items = array_map(static fn (array $item): array => [
                'title' => $item['title'],
                'text' => $item['body'],
                'href' => '/notificacoes/' . $item['id'],
                'unread' => $item['unread'],
                'when' => date('d/m H:i', strtotime($item['created_at']) ?: time()),
            ], $feed->latest($user, 8));
            return ['notifications' => $items, 'notificationCount' => $feed->unreadCount($user), 'notificationsReal' => true];
        } catch (\Throwable) {
            return ['notifications' => DemoData::enabled() ? $demo : [], 'notificationCount' => 0, 'notificationsReal' => false];
        }
    }

    /**
     * Atalhos de suporte da barra superior (ADR 0142): abrir chamado e acompanhar daqui, e o Help
     * Desk para quem atende.
     *
     * @return list<array{label:string,text:string,href:string,icon:string}>
     */
    private function supportMenu(User $user, ?string $pageId = null): array
    {
        // Central de Ajuda (ADR 0141): o tutorial da página atual, com prints marcados, no site.
        $help = $pageId !== null
            ? ['label' => 'Ajuda desta página', 'text' => 'Passo a passo com as telas marcadas', 'href' => \Source\Services\Environments::url('site', '/suporte/tela/' . rawurlencode($pageId)), 'icon' => 'help']
            : ['label' => 'Central de Ajuda', 'text' => 'Tutoriais de todas as telas', 'href' => \Source\Services\Environments::url('site', '/suporte'), 'icon' => 'help'];
        $items = [
            $help,
            ['label' => 'Abrir chamado', 'text' => 'Vai para a fila do SAC', 'href' => erp_url('/operacional/chamados#abrir'), 'icon' => 'plus'],
            ['label' => 'Meus chamados', 'text' => 'Respostas da equipe e andamento', 'href' => erp_url('/operacional/chamados'), 'icon' => 'clipboard'],
        ];
        try {
            if (\Source\Support\Access::can('support.manage', $user)) {
                $items[] = ['label' => 'SAC', 'text' => 'Atender a fila de chamados', 'href' => \Source\Services\Environments::href('helpdesk'), 'icon' => 'help'];
            }
        } catch (\Throwable) {
        }
        return $items;
    }

    /** @return list<array{label: string, href: ?string}> */
    private function breadcrumbs(?array $page, ?string $group, string $title, array $nav = [], ?string $pageId = null): array
    {
        // Página que o menu escolhido mostra em outro grupo (ADR 0398): a migalha segue o menu, sem link para a
        // visão geral antiga ("Agenda > Áreas comuns > Reservas", e não "Operacional > Rotina > Reservas").
        $navGroup = ErpNav::groupOf($nav, $pageId);
        if ($page !== null && $navGroup !== null && $navGroup !== $page['group']) {
            $crumbs = [['label' => 'Início', 'href' => '/dashboard'], ['label' => $nav[$navGroup]['label'], 'href' => null]];
            $section = ErpNav::sectionOf($nav, $pageId);
            if ($section !== '') {
                $crumbs[] = ['label' => $section, 'href' => null];
            }
            if ($title !== $page['name']) {
                $crumbs[] = ['label' => $page['name'], 'href' => $page['route']];
            }
            $crumbs[] = ['label' => $title, 'href' => null];
            return $crumbs;
        }
        $crumbs = [['label' => 'Início', 'href' => '/dashboard']];
        if ($page !== null && $page['group'] === 'dashboard') {
            return [['label' => 'Início', 'href' => null]];
        }
        $groupInfo = $group !== null ? ($this->catalog['groups'][$group] ?? null) : null;
        if ($page === null) {
            // Visão geral de módulo ou estado de erro.
            $crumbs[] = ['label' => $groupInfo['label'] ?? $title, 'href' => null];
            return $crumbs;
        }
        $pageIsModuleHome = $groupInfo !== null && $page['route'] === $groupInfo['prefix'];
        if ($groupInfo !== null && !$pageIsModuleHome) {
            $crumbs[] = ['label' => $groupInfo['label'], 'href' => $groupInfo['prefix']];
        }
        if (($page['section'] ?? '') !== '') {
            $crumbs[] = ['label' => $page['section'], 'href' => $groupInfo['prefix'] . '#' . self::anchor($page['section'])];
        }
        if ($title !== $page['name']) {
            // Detalhe de um registro dentro da página (ex.: /erp/condominios/{id}).
            $crumbs[] = ['label' => $page['name'], 'href' => $page['route']];
        }
        $crumbs[] = ['label' => $title, 'href' => null];
        return $crumbs;
    }

    /** Âncora estável para seções do hub (sem acentos). */
    public static function anchor(string $text): string
    {
        $ascii = strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return 'secao-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
