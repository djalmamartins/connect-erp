<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DemoData;
use Source\Support\Access;
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
    public const PAGE_PERMISSIONS = ['AP-004' => 'erp.condominiums.view'];

    /** Chave de sessão do condomínio em contexto (0/ausente = todos os do escopo). */
    public const CONTEXT_KEY = 'erpCondominium';

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
        ]));
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
        $template = 'pages/modules/' . $kind;
        return [$template, $this->layoutData($user, $id, $data)];
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
     * POST /contexto/condominio — troca o condomínio em contexto na navbar. Só aceita um
     * condomínio do escopo do usuário (CondominiumScope); 0 volta para "todos".
     */
    public function setCondominium(?array $data): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $back = (string)($data['back'] ?? '');
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') && !preg_match('/[\x00-\x20\\\\]/', $back) ? $back : erp_base() . '/dashboard';
        if (!csrf_verify($data ?? [])) {
            $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->flash();
            redirect(url($back));
            return;
        }
        $id = (int)(filter_var($data['condominium'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0);
        $session = new Session();
        if ($id === 0) {
            $session->unset(self::CONTEXT_KEY);
        } elseif (in_array($id, array_column($this->condominiumOptions($user), 'id'), true)) {
            $session->set(self::CONTEXT_KEY, $id);
        } else {
            // Fora do escopo: não revela se o condomínio existe.
            $this->message->warning('Condomínio indisponível para a sua conta.')->flash();
        }
        redirect(url($back));
    }

    /** @return list<array{id:int,name:string}> */
    private function condominiumOptions(User $user): array
    {
        if ($this->condominiumOptions !== null) {
            return $this->condominiumOptions;
        }
        try {
            $rows = (new CondominiumScope())->allowed($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'condominium_options_failed', 'users_id' => $user->id ?? null]);
            $rows = [];
        }
        return $this->condominiumOptions = array_values(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], array_filter($rows, static fn (array $r): bool => ($r['status'] ?? 'active') === 'active')));
    }

    /** Condomínio em contexto, revalidado a cada requisição contra o escopo atual. */
    private function currentCondominium(User $user): ?array
    {
        $session = new Session();
        $id = $session->has(self::CONTEXT_KEY) ? (int)$session->{self::CONTEXT_KEY} : 0;
        foreach ($this->condominiumOptions($user) as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }
        if ($id !== 0) {
            $session->unset(self::CONTEXT_KEY);
        }
        return null;
    }

    /** GET /erp/seguranca/codigos — códigos de recuperação mostrados uma única vez após ativar o 2FA. */
    public function recoveryCodes(): void
    {
        $user = $this->guard();
        if ($user === null) {
            return;
        }
        $session = new Session();
        $codes = $session->has('auth2faRecoveryCodes') ? (array)$session->auth2faRecoveryCodes : [];
        $session->unset('auth2faRecoveryCodes');
        header('Cache-Control: no-store');
        echo $this->view->render('pages/auth/recovery-codes', $this->layoutData($user, null, [
            'recoveryCodes' => $codes,
        ], 'Códigos de recuperação'));
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
            'condoOptions' => $this->condominiumOptions($user),
            'condoCurrent' => $this->currentCondominium($user),
            'anchor' => static fn (string $text): string => self::anchor($text),
            // Esconder o item é conveniência: a rota valida a mesma permissão no servidor.
            'canSee' => static fn (string $pageId): bool => !isset(self::PAGE_PERMISSIONS[$pageId]) || $user->can(self::PAGE_PERMISSIONS[$pageId]),
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
