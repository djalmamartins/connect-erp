<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\DemoData;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MeterReadings;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitDocuments;
use Source\Services\Erp\VirtualDisk;
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
        'AP-106' => 'erp.entries.view', 'AP-107' => 'erp.agreements.view', 'AP-108' => 'erp.entries.view', 'AP-109' => 'erp.reservations.view'];

    /** Chave de sessão do condomínio em contexto (0/ausente = todos os do escopo). */
    public const CONTEXT_KEY = CondominiumContext::SESSION_KEY;

    /** @return array<string, string> PAGE_PERMISSIONS mais as permissões dos cadastros (migration 20261108_0202). */
    public static function pagePermissions(): array
    {
        return self::PAGE_PERMISSIONS + Definitions::pagePermissions();
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
            'dashboard' => DemoData::get('dashboard'),
            'summary' => $this->summary($user, CondominiumContext::id()),
            'condoTotal' => count($this->condominiumOptions($user)),
            'operational' => $this->operationalSummary($user),
        ]));
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

    /**
     * Dados reais do fechamento mensal (AP-030, ADR 0032) no contexto da barra superior: o
     * condomínio escolhido ou, em "Todos", cada condomínio do escopo. A tela lê $closing
     * (null = sem permissão ou banco indisponível, e a tela mostra a demonstração) e
     * $canManageClosing para mostrar "Fechar" e "Reabrir" (só com um condomínio escolhido).
     *
     * @return array{closing: ?array, closingCompetence: string, canManageClosing: bool}
     */
    private function closingData(User $user): array
    {
        $competence = MonthClosing::competenceOrCurrent($_GET['competencia'] ?? null);
        $data = ['closing' => null, 'closingCompetence' => $competence, 'canManageClosing' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.closing.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $data['closing'] = (new MonthClosing())->overview($user, $context, $competence);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'month_closing_overview_failed', 'condominium_id' => $context]);
            return $data;
        }
        $data['canManageClosing'] = $context !== null && Access::can('erp.closing.manage', $user);
        return $data;
    }

    /**
     * Faturas reais (AP-032, ADR 0033) no contexto da barra superior ("Todos" = escopo do
     * usuário), filtradas por ?competencia= e ?situacao=. $invoices null = sem permissão ou sem
     * banco (a tela segue com a demonstração). As ações usam a API /api/v1/invoices.
     *
     * @return array{invoices: ?array, canManageInvoices: bool}
     */
    private function invoiceData(User $user): array
    {
        $data = ['invoices' => null, 'canManageInvoices' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.invoices.view', $user)) {
            return $data;
        }
        try {
            $data['invoices'] = (new Invoices())->list($user, CondominiumContext::id(), [
                'competence' => $_GET['competencia'] ?? null, 'status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1,
            ]);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'invoice_list_failed']);
            return $data;
        }
        $data['canManageInvoices'] = Access::can('erp.invoices.manage', $user);
        return $data;
    }

    /**
     * Cobrança bancária (AP-035 boletos, AP-036 importar retorno, AP-038 retornos com divergência;
     * ADR 0035 e 0038), só no adaptador de sandbox. $bankCharges / $bankDivergences null = sem
     * permissão ou sem banco (a tela segue com a demonstração). As ações usam a API /api/v1.
     *
     * @return array{bankCharges?: ?array, bankDivergences?: ?array, bankReady: bool, canManageCharges: bool}
     */
    private function bankingData(User $user, string $id): array
    {
        $data = ['bankReady' => false, 'canManageCharges' => false] + ($id === 'AP-035' ? ['bankCharges' => null] : []) + ($id === 'AP-038' ? ['bankDivergences' => null, 'bankReturnFiles' => null] : []);
        if (Connect::databaseDisabled() || !Access::can('erp.bank_charges.view', $user)) {
            return $data;
        }
        try {
            if ($id === 'AP-035') {
                $data['bankCharges'] = (new BankCharges())->list($user, CondominiumContext::id(), ['status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1]);
            } elseif ($id === 'AP-038') {
                $returns = new BankReturns();
                $data['bankDivergences'] = $returns->divergences($user, CondominiumContext::id());
                $data['bankReturnFiles'] = $returns->files($user, CondominiumContext::id(), ['page' => $_GET['pagina'] ?? 1]);
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'banking_screen_failed', 'page' => $id]);
            return $data;
        }
        $data['bankReady'] = true;
        $data['canManageCharges'] = Access::can('erp.bank_charges.manage', $user);
        return $data;
    }

    /** Páginas da prestação de contas com dados reais (ADR 0037): balancete e demonstrativo. */
    public const REPORT_PAGES = ['AP-079', 'AP-051'];

    /**
     * Relatórios financeiros reais (ADR 0037) no contexto da barra superior ("Todos" = escopo):
     *  - AP-079 balancete de verificação: ?de=AAAA-MM&ate=AAAA-MM (padrão: mês atual) → $trialBalance;
     *  - AP-051 demonstrativo (prestação de contas por caixa, com conciliação bancária):
     *    ?de=AAAA-MM-DD&ate=AAAA-MM-DD (padrão: do dia 1 até hoje) → $statement.
     * null = sem permissão, sem banco ou período inválido ($reportError diz qual).
     *
     * @return array{trialBalance?: ?array, statement?: ?array, reportError: ?string, reportPeriod: array{from:string, to:string}}
     */
    private function reportData(User $user, string $id): array
    {
        $trial = $id === 'AP-079';
        $from = is_string($_GET['de'] ?? null) ? $_GET['de'] : ($trial ? date('Y-m') : date('Y-m-01'));
        $to = is_string($_GET['ate'] ?? null) ? $_GET['ate'] : ($trial ? date('Y-m') : date('Y-m-d'));
        $key = $trial ? 'trialBalance' : 'statement';
        $data = [$key => null, 'reportError' => null, 'reportPeriod' => ['from' => mb_substr($from, 0, 10), 'to' => mb_substr($to, 0, 10)]];
        if (Connect::databaseDisabled() || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            return $data;
        }
        try {
            $reports = new FinancialReports();
            $data[$key] = $trial ? $reports->trialBalance($user, CondominiumContext::id(), $from, $to) : $reports->statement($user, CondominiumContext::id(), $from, $to);
        } catch (\InvalidArgumentException $error) {
            $data['reportError'] = $error->getMessage();
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'financial_report_failed', 'page' => $id]);
            $data['reportError'] = 'Não foi possível gerar o relatório agora.';
        }
        return $data;
    }

    /**
     * Inadimplência e acordos (AP-033/034, ADR 0039) no contexto da barra superior. AP-033: contas
     * vencidas por unidade ($delinquency) e a lista de acordos ($agreements, ?situacao=&pagina=).
     * AP-034: parcelas dos acordos ativos da página ($agreementParcels). null = sem permissão ou
     * sem banco (a tela segue com a demonstração). As ações usam a API /api/v1/agreements.
     *
     * @return array<string, mixed>
     */
    private function agreementData(User $user, string $id): array
    {
        $data = ['canManageAgreements' => false] + ($id === 'AP-033' ? ['delinquency' => null, 'agreements' => null] : ['agreements' => null, 'agreementParcels' => null]);
        if (Connect::databaseDisabled() || !Access::can('erp.agreements.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        $service = new Agreements();
        try {
            if ($id === 'AP-033') {
                $data['delinquency'] = $service->delinquency($user, $context);
                $data['agreements'] = $service->list($user, $context, ['status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1]);
            } else {
                $data['agreements'] = $service->list($user, $context, ['status' => 'active', 'page' => $_GET['pagina'] ?? 1]);
                $data['agreementParcels'] = self::flattenParcels(array_map(static fn (array $a): array => $service->find($user, (int)$a['id']), $data['agreements']['data']), date('Y-m-d'));
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'agreements_screen_failed', 'page' => $id]);
            return ['canManageAgreements' => false] + array_map(static fn (): mixed => null, array_diff_key($data, ['canManageAgreements' => 1]));
        }
        $data['canManageAgreements'] = Access::can('erp.agreements.manage', $user);
        return $data;
    }

    /**
     * Parcelas de vários acordos numa lista só (regra pura), com a situação de cada uma na data:
     * paga, vencida (em aberto e vencimento antes da data) ou a vencer.
     *
     * @param list<array> $agreements acordos de Agreements::find (com 'parcels')
     * @return list<array>
     */
    public static function flattenParcels(array $agreements, string $today): array
    {
        $rows = [];
        foreach ($agreements as $agreement) {
            foreach ($agreement['parcels'] ?? [] as $parcel) {
                $state = $parcel['paid_on'] !== null ? 'paid' : ((string)$parcel['due_on'] < $today ? 'late' : 'open');
                $rows[] = $parcel + ['agreement_id' => (int)$agreement['id'], 'installments' => (int)$agreement['installments'],
                    'unit_label' => $agreement['unit_label'] ?? null, 'condominium_name' => $agreement['condominium_name'] ?? null, 'state' => $state];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['state'] === 'paid', $a['due_on'], $a['agreement_id'], $a['number']] <=> [$b['state'] === 'paid', $b['due_on'], $b['agreement_id'], $b['number']]);
        return $rows;
    }

    /**
     * Regra de cobrança e índices (AP-165, ADR 0040): regra que vale para o condomínio da barra
     * superior ("Todos" = padrão da Connect) e os meses do IPCA. null = sem permissão ou sem banco.
     *
     * @return array{billingRule: ?array, priceIndexes: ?array, canManageBilling: bool}
     */
    private function billingData(User $user): array
    {
        $data = ['billingRule' => null, 'priceIndexes' => null, 'canManageBilling' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.billing_rules.view', $user)) {
            return $data;
        }
        try {
            $service = new BillingRules();
            $data['billingRule'] = $service->ruleFor($user, CondominiumContext::id());
            $data['priceIndexes'] = $service->listRates('IPCA');
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'billing_rules_screen_failed']);
            return ['billingRule' => null, 'priceIndexes' => null, 'canManageBilling' => false];
        }
        $data['canManageBilling'] = Access::can('erp.billing_rules.manage', $user);
        return $data;
    }

    /** Documentos da unidade com tela própria (ADR 0044 e 0046): página => tipo do documento. */
    public const UNIT_DOCUMENT_PAGES = ['AP-123' => 'debt_certificate', 'AP-124' => 'delinquency_declaration', 'AP-125' => 'quittance', 'AP-130' => 'consent_letter'];

    /** Recibo (AP-040, ADR 0057 e 0073): a mesma tela dos documentos, com as contas pagas da unidade para escolher. */
    public const RECEIPT_PAGE = 'AP-040';

    /**
     * Documentos da unidade (AP-123/124/125/130, ADR 0044) no contexto da barra superior: os emitidos
     * do tipo da página ($unitDocuments, ?pagina=), as unidades para emitir ($documentUnits) e, com
     * ?documento=ID, o documento com o conteúdo para imprimir ($unitDocument). null = sem permissão
     * ou sem banco (a tela segue com a demonstração). Emitir e cancelar usam /api/v1/unit-documents.
     *
     * @return array<string, mixed>
     */
    private function unitDocumentData(User $user, string $id): array
    {
        $kind = self::UNIT_DOCUMENT_PAGES[$id] ?? 'receipt';
        $data = ['documentKind' => $kind, 'unitDocuments' => null, 'unitDocument' => null, 'documentUnits' => [], 'documentError' => null, 'canManageDocuments' => false,
            'receiptUnit' => null, 'receiptEntries' => []];
        if (Connect::databaseDisabled() || !Access::can('erp.unit_documents.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $service = new UnitDocuments();
            $data['unitDocuments'] = $service->list($user, $context, ['kind' => $kind, 'page' => $_GET['pagina'] ?? 1]);
            $names = array_column($this->condominiumOptions($user), 'name', 'id');
            $data['documentUnits'] = self::documentUnits((new RegistryService())->units($user), $names, $context);
            $receiptUnit = filter_var($_GET['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($kind === 'receipt' && $receiptUnit !== false && $receiptUnit !== null && in_array((int)$receiptUnit, array_column($data['documentUnits'], 'id'), true)) {
                // Contas pagas sem recibo válido da unidade escolhida (?unidade=); a emissão confere de novo.
                $data['receiptUnit'] = (int)$receiptUnit;
                $data['receiptEntries'] = (new \Source\Services\Erp\ReceiptCandidates())->forUnit($user, (int)$receiptUnit);
            }
            $documentId = filter_var($_GET['documento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($documentId !== false && $documentId !== null) {
                try {
                    $data['unitDocument'] = $service->find($user, (int)$documentId);
                } catch (RegistryNotFound) {
                    $data['documentError'] = 'Documento não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_documents_screen_failed', 'page' => $id]);
            return ['unitDocuments' => null, 'unitDocument' => null, 'canManageDocuments' => false] + $data;
        }
        $data['canManageDocuments'] = Access::can('erp.unit_documents.manage', $user);
        return $data;
    }

    /**
     * Unidades para o seletor dos documentos (regra pura): só as do condomínio em contexto, com o nome
     * do condomínio (condomínio sem nome no escopo fica de fora), ordenadas por condomínio e unidade.
     *
     * @param list<array{id:int, condominium_id:int, label:string}> $units
     * @param array<int, string> $names condomínio => nome, do escopo do usuário
     * @return list<array{id:int, condominium_id:int, label:string, condominium_name:string}>
     */
    public static function documentUnits(array $units, array $names, ?int $context): array
    {
        $rows = [];
        foreach ($units as $unit) {
            $condo = (int)$unit['condominium_id'];
            if (!isset($names[$condo]) || ($context !== null && $condo !== $context)) {
                continue;
            }
            $rows[] = $unit + ['condominium_name' => (string)$names[$condo]];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['condominium_name'], $a['label']] <=> [$b['condominium_name'], $b['label']]);
        return $rows;
    }

    /** Tipos de relógio das leituras (ADR 0045). */
    public const METER_KINDS = ['agua' => 'Água', 'gas' => 'Gás', 'energia' => 'Energia'];

    /**
     * Leituras do mês (AP-042, ADR 0045) no contexto da barra superior: ?competencia=AAAA-MM (padrão:
     * mês atual) e ?tipo=agua|gas|energia. $meterSheet tem uma linha por relógio ativo do tipo, com a
     * leitura anterior e a da competência (consumo, valor e se já foi cobrada). null = sem permissão ou
     * sem banco (a tela segue com a demonstração). Lançar e faturar usam /api/v1/meter-readings.
     *
     * @return array<string, mixed>
     */
    private function meterReadingData(User $user): array
    {
        $competence = is_string($_GET['competencia'] ?? null) && RecurringEntries::isCompetence($_GET['competencia']) ? $_GET['competencia'] : date('Y-m');
        $kind = is_string($_GET['tipo'] ?? null) && isset(self::METER_KINDS[$_GET['tipo']]) ? $_GET['tipo'] : 'agua';
        $data = ['meterSheet' => null, 'meterCompetence' => $competence, 'meterKind' => $kind, 'canManageReadings' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.meter_readings.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $registry = new RegistryService();
            $meters = [];
            for ($page = 1; $page <= 40; $page++) {
                $chunk = $registry->list($user, 'meters', ['status' => 'active', 'filter' => $kind, 'condominium_id' => $context, 'page' => $page]);
                $meters = array_merge($meters, $chunk['data']);
                if ($page >= $chunk['pages']) {
                    break;
                }
            }
            $labels = array_column($registry->units($user), 'label', 'id');
            $readings = (new MeterReadings())->list($user, $context, ['kind' => $kind])['data'];
            $data['meterSheet'] = self::readingSheet($meters, $labels, $readings, $competence);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'meter_readings_screen_failed']);
            return ['meterSheet' => null] + $data;
        }
        $data['canManageReadings'] = Access::can('erp.meter_readings.manage', $user);
        return $data;
    }

    /**
     * Folha de leituras da competência (regra pura): uma linha por relógio, com a leitura anterior (a
     * mais recente de competência anterior, senão a inicial do relógio) e a leitura da competência, se
     * houver. Ordenada por condomínio e unidade.
     *
     * @param list<array> $meters relógios (RegistryService 'meters')
     * @param array<int, string> $unitLabels unidade => rótulo
     * @param list<array> $readings leituras do tipo (MeterReadings::list), mais novas primeiro
     * @return list<array{meter_id:int, condominium_id:int, condominium_name:?string, unit_label:string, number:string, previous:float, reading:?array}>
     */
    public static function readingSheet(array $meters, array $unitLabels, array $readings, string $competence): array
    {
        $current = [];
        $previous = [];
        foreach ($readings as $r) {
            $meter = (int)$r['meter_id'];
            if ($r['competence'] === $competence) {
                $current[$meter] = $r;
            } elseif ($r['competence'] < $competence && (!isset($previous[$meter]) || $r['competence'] > $previous[$meter]['competence'])) {
                $previous[$meter] = $r;
            }
        }
        $rows = [];
        foreach ($meters as $m) {
            $id = (int)$m['id'];
            $rows[] = [
                'meter_id' => $id, 'condominium_id' => (int)$m['condominium_id'], 'condominium_name' => $m['condominium_name'] ?? null,
                'unit_label' => (string)($unitLabels[(int)$m['unit_id']] ?? '—'), 'number' => (string)$m['number'],
                'previous' => isset($current[$id]) ? (float)$current[$id]['previous_reading'] : (isset($previous[$id]) ? (float)$previous[$id]['reading'] : (float)($m['initial_reading'] ?? 0)),
                'reading' => $current[$id] ?? null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [(string)$a['condominium_name'], $a['unit_label'], $a['number']] <=> [(string)$b['condominium_name'], $b['unit_label'], $b['number']]);
        return $rows;
    }

    /**
     * Disco Virtual (AP-122, ADR 0047 e 0049) no contexto da barra superior: arquivos por pasta
     * (?pasta=, ?q=, ?pagina=) e os condomínios do escopo para enviar. null = sem permissão ou sem
     * banco (a tela segue com a demonstração). Enviar, baixar e excluir usam /api/v1/documents.
     *
     * @return array<string, mixed>
     */
    private function virtualDiskData(User $user): array
    {
        $folder = is_string($_GET['pasta'] ?? null) && isset(VirtualDisk::FOLDERS[$_GET['pasta']]) ? $_GET['pasta'] : null;
        $q = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
        $data = ['diskFiles' => null, 'diskFolder' => $folder, 'diskQuery' => $q, 'diskCondominiums' => [], 'canManageFiles' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.documents.view', $user)) {
            return $data;
        }
        try {
            $data['diskFiles'] = (new VirtualDisk())->list($user, CondominiumContext::id(), ['folder' => $folder, 'q' => $q, 'page' => $_GET['pagina'] ?? 1]);
            $data['diskCondominiums'] = $this->condominiumOptions($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'virtual_disk_screen_failed']);
            return ['diskFiles' => null] + $data;
        }
        $data['canManageFiles'] = Access::can('erp.documents.manage', $user);
        return $data;
    }

    /**
     * Integrações (AP-171, ADR 0091 e 0161), só leitura: o mesmo IntegrationStatus de GET /api/v1/integrations
     * (banco, e-mail, índices, fila de avisos e antivírus), só com números e sem endereços. Sem banco ou sem
     * erp.settings.view, null = a demonstração.
     */
    private function integrationData(User $user): array
    {
        $data = ['integrations' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.settings.view', $user)) {
            return $data;
        }
        try {
            $data['integrations'] = (new \Source\Services\Erp\IntegrationStatus())->overview($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'integrations_screen_failed']);
        }
        return $data;
    }

    /**
     * Agendamentos Pendentes (AP-114, ADR 0051 e 0070): as reservas de áreas comuns aguardando
     * aprovação no condomínio da barra superior, pela listagem do motor (escopo e paginação dele).
     * Sem banco ou sem erp.reservations.view, null = a demonstração.
     */
    private function pendingReservationData(User $user): array
    {
        $data = ['pendingReservations' => null, 'pendingAreas' => [], 'pendingUnits' => [], 'canDecideReservations' => false, 'flash' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.reservations.view', $user)) {
            return $data;
        }
        try {
            $service = new RegistryService();
            $data['pendingReservations'] = $service->list($user, 'reservations', [
                'condominium_id' => CondominiumContext::id(), 'status' => 'active', 'page' => $_GET['pagina'] ?? 1,
            ]);
            // Nomes da área e da unidade para a lista (a reserva guarda só os números).
            $areas = $service->references($user, RegistryService::definition('reservations'))['common_area_id'] ?? [];
            $data['pendingAreas'] = array_column($areas, 'label', 'id');
            $data['pendingUnits'] = array_column($service->units($user), 'label', 'id');
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'pending_reservations_screen_failed']);
            return $data;
        }
        $data['canDecideReservations'] = Access::can('erp.reservations.manage', $user);
        $data['flash'] = (new \Source\Core\Session())->flash();
        return $data;
    }

    /**
     * Painel do condomínio (AP-002, ADR 0054 e 0071) pelo PanelSummaries, o mesmo serviço de
     * GET /api/v1/panels/condominium/{id}, para o condomínio da barra superior. Em "Todos" a tela pede
     * a escolha de um condomínio. Sem banco ou sem erp.units.view, condoPanelReady false = demonstração.
     */
    private function condominiumPanelData(User $user): array
    {
        $data = ['condoPanelReady' => false, 'condoPanel' => null, 'condoPanelError' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        $data['condoPanelReady'] = true;
        $context = CondominiumContext::id();
        if ($context === null) {
            return $data;
        }
        try {
            $data['condoPanel'] = (new \Source\Services\Erp\PanelSummaries())->condominium($user, $context);
        } catch (\Source\Services\Erp\Registry\RegistryForbidden) {
            $data['condoPanelError'] = 'Seu perfil não tem permissão para todos os dados do painel do condomínio (financeiro e fechamento).';
        } catch (RegistryNotFound) {
            $data['condoPanelError'] = 'Condomínio fora do seu escopo de acesso.';
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'condominium_panel_failed']);
            $data['condoPanelError'] = 'Não foi possível montar o painel agora. Tente de novo em instantes.';
        }
        return $data;
    }

    /**
     * Painel da unidade (AP-003, ADR 0054 e 0071): escolhe-se a unidade (?unidade=) entre as do
     * condomínio da barra superior; os dados vêm do PanelSummaries (GET /api/v1/panels/unit/{id}).
     */
    /**
     * Fração ideal e área em grade (AP-167, ADR 0140): unidades ativas do condomínio da barra superior,
     * pelo mesmo serviço de GET /api/v1/unit-measures. Gravar e dividir usam a API (erp.js).
     */
    private function unitMeasuresData(User $user): array
    {
        $data = ['measuresReady' => false, 'measures' => null, 'measuresError' => null, 'canManageMeasures' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        $data['measuresReady'] = true;
        $data['canManageMeasures'] = Access::can('erp.units.manage', $user);
        $context = CondominiumContext::id();
        if ($context === null) {
            return $data;
        }
        try {
            $data['measures'] = (new \Source\Services\Erp\UnitBatch())->measures($user, $context);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_measures_screen_failed']);
            $data['measuresError'] = 'Não foi possível carregar as unidades agora. Tente de novo em instantes.';
        }
        return $data;
    }

    private function unitPanelData(User $user): array
    {
        $data = ['unitPanelReady' => false, 'unitPanel' => null, 'unitPanelUnits' => [], 'unitPanelSelected' => null, 'unitPanelError' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $units = (new RegistryService())->units($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_panel_failed']);
            return $data;
        }
        $data['unitPanelReady'] = true;
        $data['unitPanelUnits'] = array_values(array_filter($units, static fn (array $u): bool => $context === null || $u['condominium_id'] === $context));
        $selected = filter_var($_GET['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selected === false || $selected === null) {
            return $data;
        }
        $data['unitPanelSelected'] = (int)$selected;
        try {
            $data['unitPanel'] = (new \Source\Services\Erp\PanelSummaries())->unit($user, (int)$selected);
        } catch (\Source\Services\Erp\Registry\RegistryForbidden) {
            $data['unitPanelError'] = 'Seu perfil não tem permissão para todos os dados do painel da unidade (pessoas, financeiro e documentos).';
        } catch (RegistryNotFound) {
            $data['unitPanelError'] = 'Unidade não encontrada no seu escopo de condomínios.';
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_panel_failed']);
            $data['unitPanelError'] = 'Não foi possível montar o painel agora. Tente de novo em instantes.';
        }
        return $data;
    }

    /** Tamanho legível em pt-BR (regra pura): 512 B, 12,5 KB, 3,2 MB. */
    public static function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $kb = $bytes / 1024;
        return $kb < 1024 ? number_format($kb, 1, ',', '.') . ' KB' : number_format($kb / 1024, 1, ',', '.') . ' MB';
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
        if ($kind === 'closing') {
            $data += $this->closingData($user);
        }
        if ($id === 'AP-032') {
            $data += $this->invoiceData($user);
        }
        if (in_array($id, self::REPORT_PAGES, true)) {
            $data += $this->reportData($user, $id);
        }
        if ($id === 'AP-165') {
            $data += $this->billingData($user);
        }
        if (in_array($id, ['AP-033', 'AP-034'], true)) {
            $data += $this->agreementData($user, $id);
        }
        if (in_array($id, ['AP-035', 'AP-036', 'AP-038'], true)) {
            $data += $this->bankingData($user, $id);
        }
        if ($id === 'AP-122') {
            $data += $this->virtualDiskData($user);
        }
        if ($id === 'AP-042') {
            $data += $this->meterReadingData($user);
        }
        if ($id === 'AP-114') {
            $data += $this->pendingReservationData($user);
        }
        if ($id === 'AP-171') {
            $data += $this->integrationData($user);
        }
        if ($id === 'AP-002') {
            $data += $this->condominiumPanelData($user);
        }
        if ($id === 'AP-003') {
            $data += $this->unitPanelData($user);
        }
        if ($id === 'AP-167') {
            $data += $this->unitMeasuresData($user);
        }
        if (isset(self::UNIT_DOCUMENT_PAGES[$id]) || $id === self::RECEIPT_PAGE) {
            $data += $this->unitDocumentData($user, $id);
        }
        if (isset(ImportScreens::pages()[$id])) {
            $data += (new ImportScreens())->data($user, $id);
        }
        if (isset(CommunicationScreens::PAGES[$id])) {
            $data += (new CommunicationScreens())->data($user, $id);
        }
        if (isset(CollectionScreens::PAGES[$id])) {
            $data += (new CollectionScreens())->data($user, $id);
        }
        if (isset(CollectionReportScreens::PAGES[$id])) {
            $data += (new CollectionReportScreens())->data($user, $id);
        }
        if (isset(ChartScreens::PAGES[$id])) {
            $data += (new ChartScreens())->data($user, $id);
        }
        if (isset(EntryShortcutScreens::PAGES[$id])) {
            $data += (new EntryShortcutScreens())->data($user, $id, fn (): array => array_column($this->condominiumOptions($user), 'name', 'id'));
        }
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
            $id === 'AP-038' && ($data['bankDivergences'] ?? null) !== null => 'pages/finance/bank-divergences',
            (isset(self::UNIT_DOCUMENT_PAGES[$id]) || $id === self::RECEIPT_PAGE) && ($data['unitDocuments'] ?? null) !== null => 'pages/documents/unit-document',
            isset(EntryShortcutScreens::PAGES[$id]) && ($data['shortcutReady'] ?? false) => EntryShortcutScreens::PAGES[$id],
            isset(ImportScreens::pages()[$id]) && ($data['importReady'] ?? false) => 'pages/imports/import',
            isset(CommunicationScreens::PAGES[$id]) && ($data['commReady'] ?? false) => CommunicationScreens::PAGES[$id],
            isset(CollectionScreens::PAGES[$id]) && ($data['collReady'] ?? false) => CollectionScreens::PAGES[$id],
            isset(CollectionReportScreens::PAGES[$id]) && ($data['cReportReady'] ?? false) => 'pages/reports/collection-report',
            isset(ChartScreens::PAGES[$id]) && ($data['chartReady'] ?? false) => 'pages/reports/chart',
            $id === 'AP-042' && ($data['meterSheet'] ?? null) !== null => 'pages/operational/meter-readings',
            $id === 'AP-122' && ($data['diskFiles'] ?? null) !== null => 'pages/documents/virtual-disk',
            $id === 'AP-114' && ($data['pendingReservations'] ?? null) !== null => 'pages/operational/pending-reservations',
            $id === 'AP-171' && ($data['integrations'] ?? null) !== null => 'pages/settings/integrations',
            $id === 'AP-002' && ($data['condoPanelReady'] ?? false) => 'pages/condominiums/panel',
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
        $icons = require self::THEME . 'components/layout/icons.php';
        $dashboard = DemoData::get('dashboard');

        return array_merge([
            'head' => $this->seo->render($title . ' · Connect ERP', 'ERP da administradora Connect.', erp_url(), theme('/assets/images/favicon.png'), false),
            'title' => $title,
            'user' => $user,
            'catalog' => $this->catalog,
            'currentPageId' => $pageId,
            'currentPage' => $page,
            'currentGroup' => $group,
            'breadcrumbs' => $this->breadcrumbs($page, $group, $title),
            'demo' => DemoData::enabled(),
            // Sino (ADR 0142): as notificações reais da pessoa, as mesmas do Studio e do Help Desk.
            // Sem banco (smoke de renderização) cai nas de demonstração, quando ligadas.
            ...$this->bell($user, $dashboard['notifications'] ?? []),
            'supportMenu' => $this->supportMenu($user, $pageId),
            'withNavigation' => true,
            'condoOptions' => CondominiumContext::booted() ? CondominiumContext::options() : CondominiumContext::withSlugs($this->condominiumOptions($user)),
            'condoCurrent' => CondominiumContext::current(),
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
            ['label' => 'Abrir chamado', 'text' => 'Vai para a fila do Help Desk', 'href' => erp_url('/operacional/chamados#abrir'), 'icon' => 'plus'],
            ['label' => 'Meus chamados', 'text' => 'Respostas da equipe e andamento', 'href' => erp_url('/operacional/chamados'), 'icon' => 'clipboard'],
        ];
        try {
            if (\Source\Support\Access::can('support.manage', $user)) {
                $items[] = ['label' => 'Help Desk', 'text' => 'Atender a fila de chamados', 'href' => \Source\Services\Environments::href('helpdesk'), 'icon' => 'help'];
            }
        } catch (\Throwable) {
        }
        return $items;
    }

    /** @return list<array{label: string, href: ?string}> */
    private function breadcrumbs(?array $page, ?string $group, string $title): array
    {
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
