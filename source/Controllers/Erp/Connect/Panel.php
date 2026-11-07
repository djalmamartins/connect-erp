<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\DemoData;
use Source\Support\Access;

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

    /** @var array{groups: array<string, array<string, string>>, pages: array<string, array<string, string>>} */
    private array $catalog;

    public function __construct()
    {
        parent::__construct(self::THEME);
        $this->catalog = require self::THEME . 'components/layout/catalog.php';
    }

    /** GET /erp */
    public function root(): void
    {
        redirect(Auth::user() ? '/erp/dashboard' : '/erp/login');
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
        // Até a aprovação da identidade visual (login + dashboard), cada rota mostra o
        // estado "em construção" com o que a página terá e o que falta, sem dados simulados.
        echo $this->view->render('pages/system/pending', $this->layoutData($user, $id));
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
            redirect('/erp/login');
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
    private function layoutData(User $user, ?string $pageId, array $extra = [], ?string $title = null): array
    {
        $page = $pageId !== null ? ($this->catalog['pages'][$pageId] ?? null) : null;
        $group = $page['group'] ?? ($extra['hubGroup'] ?? null);
        $title ??= $page['name'] ?? 'Connect ERP';
        $icons = require self::THEME . 'components/layout/icons.php';
        $dashboard = DemoData::get('dashboard');

        return array_merge([
            'head' => $this->seo->render($title . ' · Connect ERP', 'ERP da administradora Connect.', url('/erp'), theme('/assets/images/favicon.png'), false),
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
            'anchor' => static fn (string $text): string => self::anchor($text),
            'icon' => static function (string $name, string $class = '') use ($icons): string {
                $paths = $icons[$name] ?? $icons['info'];
                return '<svg class="erp-icon ' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
            },
        ], $extra);
    }

    /** @return list<array{label: string, href: ?string}> */
    private function breadcrumbs(?array $page, ?string $group, string $title): array
    {
        $crumbs = [['label' => 'Início', 'href' => '/erp/dashboard']];
        if ($group !== null && $group !== 'dashboard' && isset($this->catalog['groups'][$group])) {
            $crumbs[] = ['label' => $this->catalog['groups'][$group]['label'], 'href' => $page ? '/erp' . $this->catalog['groups'][$group]['prefix'] : null];
        }
        if ($page !== null && ($page['section'] ?? '') !== '') {
            $crumbs[] = ['label' => $page['section'], 'href' => '/erp' . $this->catalog['groups'][$group]['prefix'] . '#' . self::anchor($page['section'])];
        }
        if ($page === null || $page['group'] !== 'dashboard') {
            if ($page !== null) {
                $crumbs[] = ['label' => $title, 'href' => null];
            } elseif ($group === null) {
                $crumbs[] = ['label' => $title, 'href' => null];
            }
        } else {
            $crumbs = [['label' => 'Início', 'href' => null]];
        }
        return $crumbs;
    }

    /** Âncora estável para seções do hub (sem acentos). */
    public static function anchor(string $text): string
    {
        $ascii = strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return 'secao-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
