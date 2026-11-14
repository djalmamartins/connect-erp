<?php

declare(strict_types=1);

namespace Source\Controllers\App\Resident;

use DomainException;
use InvalidArgumentException;
use Source\Controllers\Api\V1\ResidentApp;
use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentPortal;
use Source\Services\Resident\ResidentSession;

/**
 * Telas do aplicativo do morador (ADR 0302), em app.<domínio> (ou /app no host único). Mesmos serviços da
 * API /api/v1/resident. Cada tela confere a sessão, o vínculo de morador e a função na matriz do portal;
 * formulários com CSRF. Conta sem vínculo vê "sem acesso" e não enxerga nada do ERP.
 */
final class AppScreens extends Controller
{
    /** Menu: chave, rótulo, caminho, função da matriz (null = sempre), ícone. */
    public const MENU = [
        ['home', 'Início', '/', null, 'home'],
        ['charges', 'Boletos', '/boletos', 'boletos', 'receipt'],
        ['accountability', 'Prestação de contas', '/prestacao-de-contas', 'accountability', 'chart'],
        ['announcements', 'Comunicados', '/comunicados', 'announcements', 'megaphone'],
        ['reservations', 'Reservas', '/reservas', 'reservations', 'calendar'],
        ['deliveries', 'Encomendas', '/encomendas', 'deliveries', 'box'],
        ['visits', 'Visitantes', '/visitantes', 'visitors', 'user-check'],
        ['occurrences', 'Ocorrências', '/ocorrencias', 'occurrences', 'alert'],
        ['assemblies', 'Assembleias', '/assembleias', 'assemblies', 'users'],
        ['documents', 'Documentos', '/documentos', 'documents', 'file'],
        ['unit', 'Minha unidade', '/minha-unidade', null, 'key'],
    ];
    /** Barra inferior no celular (as demais ficam em "Mais"). */
    public const TABS = ['home', 'charges', 'reservations', 'deliveries'];

    private ResidentPortal $portal;

    public function __construct()
    {
        parent::__construct(moves_container_path('residents', CONF_VIEW_APP) . '/');
        $this->portal = new ResidentPortal();
    }

    /** Itens do menu que o vínculo enxerga (regra pura). */
    public static function menu(array $features, string $active): array
    {
        $out = [];
        foreach (self::MENU as [$key, $label, $path, $feature, $icon]) {
            if ($feature !== null && !in_array($feature, $features, true)) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $label, 'path' => $path, 'icon' => $icon, 'active' => $key === $active, 'tab' => in_array($key, self::TABS, true)];
        }
        return $out;
    }

    public function home(): void
    {
        $this->page('home', null, 'pages/home', 'Início', fn (User $u, array $link): array => ['summary' => $this->portal->summary($link)]);
    }

    public function charges(): void
    {
        $this->page('charges', 'boletos', 'pages/charges', 'Boletos', fn (User $u, array $link): array => ['statement' => $this->portal->charges($link, $_GET['de'] ?? null, $_GET['ate'] ?? null)]);
    }

    public function chargePdf(array $data): void
    {
        $context = $this->context('boletos');
        if ($context === null) {
            return;
        }
        try {
            $pdf = $this->portal->chargePdf($context[1], (int)($data['id'] ?? 0));
        } catch (RegistryNotFound | DomainException $error) {
            $this->message->warning($error instanceof DomainException ? $error->getMessage() : 'Boleto não encontrado.')->flash();
            redirect(app_url('/boletos'));
            return;
        } catch (\RuntimeException $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'resident_charge_pdf_failed']);
            $this->message->warning('O banco não devolveu o boleto agora. Tente de novo em alguns minutos.')->flash();
            redirect(app_url('/boletos'));
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="boleto.pdf"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    public function accountability(): void
    {
        $this->page('accountability', 'accountability', 'pages/accountability', 'Prestação de contas', fn (User $u, array $link): array => ['links' => $this->portal->accountability($link)]);
    }

    public function announcements(): void
    {
        $this->page('announcements', 'announcements', 'pages/announcements', 'Comunicados', fn (User $u, array $link): array => ['announcements' => $this->portal->announcements($link)]);
    }

    public function reservations(): void
    {
        $this->page('reservations', 'reservations', 'pages/reservations', 'Reservas', fn (User $u, array $link): array => [
            'areas' => $this->portal->commonAreas($link), 'mine' => $this->portal->reservations($link)]);
    }

    public function reserve(array $data): void
    {
        $this->action('reservations', '/reservas', $data, function (User $u, array $link) use ($data): string {
            $saved = $this->portal->requestReservation($u, $link, self::only($data, ['common_area_id', 'reserved_on', 'starts_time', 'ends_time', 'notes']));
            return ($saved['status'] ?? '') === 'confirmed' ? 'Reserva confirmada.' : 'Pedido de reserva enviado. Você recebe a resposta da administração.';
        });
    }

    public function cancelReservation(array $data): void
    {
        $this->action('reservations', '/reservas', $data, function (User $u, array $link) use ($data): string {
            $this->portal->cancelReservation($u, $link, (int)($data['id'] ?? 0));
            return 'Reserva cancelada.';
        });
    }

    public function waitlist(array $data): void
    {
        $this->action('reservations', '/reservas', $data, function (User $u, array $link) use ($data): string {
            $this->portal->joinWaitlist($u, $link, self::only($data, ['common_area_id', 'desired_on', 'notes']));
            return 'Você entrou na lista de espera. Avisamos se a data liberar.';
        });
    }

    public function occurrences(): void
    {
        $this->page('occurrences', 'occurrences', 'pages/occurrences', 'Ocorrências', fn (User $u, array $link): array => ['occurrences' => $this->portal->occurrences($link)]);
    }

    public function reportOccurrence(array $data): void
    {
        $this->action('occurrences', '/ocorrencias', $data, function (User $u, array $link) use ($data): string {
            $this->portal->reportOccurrence($u, $link, self::only($data, ['subject', 'description', 'priority', 'occurred_on']));
            return 'Ocorrência registrada. A administração vai acompanhar.';
        });
    }

    public function deliveries(): void
    {
        $this->page('deliveries', 'deliveries', 'pages/deliveries', 'Encomendas', fn (User $u, array $link): array => ['deliveries' => $this->portal->deliveries($link)]);
    }

    public function visits(): void
    {
        $this->page('visits', 'visitors', 'pages/visits', 'Visitantes', fn (User $u, array $link): array => ['visits' => $this->portal->visits($link)]);
    }

    public function authorizeVisit(array $data): void
    {
        $this->action('visitors', '/visitantes', $data, function (User $u, array $link) use ($data): string {
            $this->portal->authorizeVisit($u, $link, self::only($data, ['visitor_name', 'visitor_document', 'expected_on', 'expected_until', 'notes']));
            return 'Visita avisada à portaria.';
        });
    }

    public function cancelVisit(array $data): void
    {
        $this->action('visitors', '/visitantes', $data, function (User $u, array $link) use ($data): string {
            $this->portal->cancelVisit($u, $link, (int)($data['id'] ?? 0));
            return 'Visita cancelada.';
        });
    }

    public function assemblies(): void
    {
        $this->page('assemblies', 'assemblies', 'pages/assemblies', 'Assembleias', fn (User $u, array $link): array => ['assemblies' => $this->portal->assemblies($link)]);
    }

    public function assembly(array $data): void
    {
        $this->page('assemblies', 'assemblies', 'pages/assembly', 'Assembleia', fn (User $u, array $link): array => ['assembly' => $this->portal->assembly($link, (int)($data['id'] ?? 0))]);
    }

    public function documents(): void
    {
        $this->page('documents', 'documents', 'pages/documents', 'Documentos', fn (User $u, array $link): array => ['documents' => $this->portal->documents($link)]);
    }

    public function documentFile(array $data): void
    {
        $context = $this->context('documents');
        if ($context === null) {
            return;
        }
        try {
            $file = $this->portal->documentFile($context[1], (int)($data['id'] ?? 0));
        } catch (RegistryNotFound) {
            $this->message->warning('Documento não encontrado.')->flash();
            redirect(app_url('/documentos'));
            return;
        }
        ResidentApp::sendFile($file);
    }

    public function unit(): void
    {
        $this->page('unit', null, 'pages/unit', 'Minha unidade', fn (User $u, array $link): array => ['petsVehicles' => $this->portal->petsVehicles($link)]);
    }

    /** POST /unidade — troca o vínculo que está vendo (só entre os da própria conta). */
    public function chooseUnit(array $data): void
    {
        $user = Auth::user();
        if ($user === null || !csrf_verify($data)) {
            redirect(app_url('/'));
            return;
        }
        [$all] = ResidentSession::resolve($user);
        $wanted = (int)($data['resident_id'] ?? 0);
        foreach ($all as $link) {
            if ($link['resident_id'] === $wanted) {
                ResidentSession::choose($wanted);
                $this->message->success('Agora você está vendo ' . $link['unit_label'] . ' · ' . $link['condominium_name'] . '.')->flash();
                break;
            }
        }
        redirect(app_url('/'));
    }

    /** GET /seguranca/codigos — códigos de recuperação do 2FA recém-configurado, uma vez só. */
    public function recoveryCodes(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(app_url('/login'));
            return;
        }
        $session = new \Source\Core\Session();
        $codes = $session->auth2faRecoveryCodes ?? null;
        $session->unset('auth2faRecoveryCodes');
        [$all, $link] = ResidentSession::resolve($user);
        $this->render('pages/codes', 'Códigos de recuperação', 'unit', $user, $all, $link, ['codes' => is_array($codes) ? $codes : []]);
    }

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode([
            'name' => 'Connect Morador', 'short_name' => 'Connect', 'lang' => 'pt-BR',
            'description' => 'Boletos, reservas, encomendas, visitantes e avisos do seu condomínio.',
            'start_url' => app_url('/'), 'scope' => app_url('/'), 'display' => 'standalone',
            'background_color' => '#0d2e27', 'theme_color' => '#0d2e27',
            'icons' => [
                ['src' => themeApp('/assets/images/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => themeApp('/assets/images/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => themeApp('/assets/images/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Service worker na raiz do app (o escopo é a pasta de onde ele vem). Só guarda a tela "sem conexão". */
    public function serviceWorker(): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: ' . (app_base() === '' ? '/' : app_base() . '/'));
        $offline = json_encode(app_url('/offline'), JSON_UNESCAPED_SLASHES);
        $file = (string)file_get_contents(moves_container_path('residents', CONF_VIEW_APP) . '/assets/js/sw.js');
        echo str_replace("'__OFFLINE_URL__'", (string)$offline, $file);
    }

    public function offline(): void
    {
        header('Cache-Control: public, max-age=300');
        echo $this->view->render('pages/offline', ['head' => $this->seo->render('Sem conexão · Connect Morador', '', app_url('/offline'), themeApp('/assets/images/icon-512.png'), false)]);
    }

    public function error(array $data): void
    {
        $code = (int)($data['errcode'] ?? 404);
        http_response_code(in_array($code, [400, 403, 404, 405, 500, 501], true) ? $code : 404);
        echo $this->view->render('pages/error', ['head' => $this->seo->render('Página não encontrada · Connect Morador', '', app_url('/'), themeApp('/assets/images/icon-512.png'), false), 'code' => $code]);
    }

    /** @param list<string> $keys */
    private static function only(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            }
        }
        return $out;
    }

    /**
     * Sessão + vínculo + função; senão redireciona (login), mostra "sem acesso" ou "não liberado".
     *
     * @return array{0: User, 1: array, 2: list<array>}|null
     */
    private function context(?string $feature): ?array
    {
        $this->headers();
        $user = Auth::user();
        if ($user === null) {
            redirect(app_url('/login'));
            return null;
        }
        [$all, $link] = ResidentSession::resolve($user);
        if ($link === null) {
            http_response_code(403);
            $this->render('pages/no-access', 'Sem acesso', 'home', $user, [], null, []);
            return null;
        }
        if ($feature !== null && !in_array($feature, $link['features'], true)) {
            http_response_code(403);
            $this->render('pages/disabled', 'Ainda não liberado', '', $user, $all, $link, ['feature' => ResidentPortalPermissions::FEATURES[$feature] ?? $feature]);
            return null;
        }
        return [$user, $link, $all];
    }

    private function page(string $active, ?string $feature, string $template, string $title, callable $data): void
    {
        $context = $this->context($feature);
        if ($context === null) {
            return;
        }
        [$user, $link, $all] = $context;
        try {
            $extra = $data($user, $link);
        } catch (ResidentFeatureDisabled) {
            http_response_code(403);
            $this->render('pages/disabled', 'Ainda não liberado', $active, $user, $all, $link, ['feature' => $title]);
            return;
        } catch (RegistryNotFound) {
            $this->message->warning('Não encontrado.')->flash();
            redirect(app_url('/'));
            return;
        } catch (InvalidArgumentException $error) {
            $this->message->warning($error->getMessage())->flash();
            redirect(app_url('/'));
            return;
        }
        $this->render($template, $title, $active, $user, $all, $link, $extra);
    }

    private function action(string $feature, string $back, array $data, callable $run): void
    {
        $context = $this->context($feature);
        if ($context === null) {
            return;
        }
        if (!csrf_verify($data)) {
            $this->message->error('Sessão expirada. Atualize a página e tente de novo.')->flash();
            redirect(app_url($back));
            return;
        }
        if (request_limit('resident_app_write', 30, 300)) {
            $this->message->warning('Muitas ações em pouco tempo. Aguarde alguns minutos.')->flash();
            redirect(app_url($back));
            return;
        }
        try {
            $this->message->success($run($context[0], $context[1]))->flash();
        } catch (RegistryValidationException $error) {
            $this->message->warning(implode(' ', array_unique(array_values($error->errors))))->flash();
        } catch (RegistryNotFound) {
            $this->message->warning('Não encontrado.')->flash();
        } catch (InvalidArgumentException | DomainException $error) {
            $this->message->warning($error->getMessage())->flash();
        } catch (ResidentFeatureDisabled $error) {
            $this->message->warning($error->getMessage())->flash();
        }
        redirect(app_url($back));
    }

    private function render(string $template, string $title, string $active, User $user, array $all, ?array $link, array $data): void
    {
        echo $this->view->render($template, $data + [
            'head' => $this->seo->render($title . ' · Connect Morador', 'Aplicativo do morador da Connect.', app_url('/'), themeApp('/assets/images/icon-512.png'), false),
            'title' => $title,
            'user' => $user,
            'links' => $all,
            'link' => $link,
            'menu' => self::menu($link['features'] ?? [], $active),
        ]);
    }

    private function headers(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: same-origin');
            header('X-Frame-Options: DENY');
        }
    }
}
