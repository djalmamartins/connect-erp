<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\DemoData;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
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
    public const PAGE_PERMISSIONS = ['AP-004' => 'erp.condominiums.view', 'CN-001' => 'erp.inspections.view'];

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
        ]));
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
        $data = ['bankReady' => false, 'canManageCharges' => false] + ($id === 'AP-035' ? ['bankCharges' => null] : []) + ($id === 'AP-038' ? ['bankDivergences' => null] : []);
        if (Connect::databaseDisabled() || !Access::can('erp.bank_charges.view', $user)) {
            return $data;
        }
        try {
            if ($id === 'AP-035') {
                $data['bankCharges'] = (new BankCharges())->list($user, CondominiumContext::id(), ['status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1]);
            } elseif ($id === 'AP-038') {
                $data['bankDivergences'] = (new BankReturns())->divergences($user, CondominiumContext::id());
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
        if (in_array($id, ['AP-035', 'AP-036', 'AP-038'], true)) {
            $data += $this->bankingData($user, $id);
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
            $id === 'AP-035' && ($data['bankCharges'] ?? null) !== null => 'pages/finance/bank-charges',
            $id === 'AP-036' && ($data['bankReady'] ?? false) => 'pages/finance/bank-return-import',
            $id === 'AP-038' && ($data['bankDivergences'] ?? null) !== null => 'pages/finance/bank-divergences',
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
            'notifications' => $dashboard['notifications'] ?? [],
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
