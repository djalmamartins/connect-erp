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
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentPortal;
use Source\Services\Resident\ResidentProfile;
use Source\Services\Resident\ResidentSession;
use Source\Services\Resident\ResidentCertificates;
use Source\Services\Resident\ResidentHousehold;
use Source\Services\Resident\ResidentNotAllowed;
use Source\Services\Resident\ResidentTickets;
use Source\Services\Resident\ResidentTwoFactor;

/**
 * Telas do aplicativo do morador (ADR 0302), em app.<domínio> (ou /app no host único). Mesmos serviços da
 * API /api/v1/resident. Cada tela confere a sessão, o vínculo de morador e a função na matriz do portal;
 * formulários com CSRF. Conta sem vínculo vê "sem acesso" e não enxerga nada do ERP.
 */
final class AppScreens extends Controller
{
    /** Menu: chave, rótulo, caminho, função da matriz (null = sempre), ícone. */
    /** [chave, rótulo, caminho, função exigida, ícone, aba da barra inferior a que pertence] — revisão de 15/11. */
    public const MENU = [
        ['home', 'Início', '/', null, 'home', 'home'],
        ['resolve', 'Resolva fácil', '/resolva-facil', null, 'grid', 'resolve'],
        ['charges', 'Boletos', '/boletos', 'boletos', 'receipt', 'resolve'],
        ['certificates', 'CND', '/cnd', 'boletos', 'shield', 'resolve'],
        ['accountability', 'Prestação de contas', '/prestacao-de-contas', 'accountability', 'chart', 'resolve'],
        ['announcements', 'Comunicados', '/comunicados', 'announcements', 'megaphone', 'resolve'],
        ['reservations', 'Reservas', '/reservas', 'reservations', 'calendar', 'resolve'],
        ['assemblies', 'Assembleia digital', '/assembleias', 'assemblies', 'users', 'resolve'],
        ['documents', 'Documentos', '/documentos', 'documents', 'file', 'resolve'],
        ['occurrences', 'Chamados', '/ocorrencias', 'occurrences', 'alert', 'resolve'],
        ['unit', 'Unidade', '/minha-unidade', null, 'key', 'unit'],
        ['household', 'Moradores', '/minha-unidade/moradores', 'household', 'users', 'unit'],
        ['deliveries', 'Correspondências', '/encomendas', 'deliveries', 'box', 'unit'],
        ['visits', 'Autorizar entrada', '/visitantes', 'visitors', 'user-check', 'unit'],
        ['profile', 'Perfil', '/perfil', null, 'user', 'profile'],
    ];
    /** Barra inferior no celular: Início, Resolva fácil, Unidade e Perfil (revisão de 15/11). */
    public const TABS = ['home', 'resolve', 'unit', 'profile'];
    /** Páginas fora do menu e a aba que fica acesa nelas. */
    private const SECTION_OF = ['notifications' => 'home', 'search' => 'home', 'security' => 'profile', 'welcome' => 'home'];

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
        $section = self::SECTION_OF[$active] ?? null;
        foreach (self::MENU as [$key, $label, $path, $feature, $icon, $tabOf]) {
            if ($section === null && $key === $active) {
                $section = $tabOf;
            }
        }
        foreach (self::MENU as [$key, $label, $path, $feature, $icon]) {
            if ($feature !== null && !in_array($feature, $features, true)) {
                continue;
            }
            $tab = in_array($key, self::TABS, true);
            $out[] = ['key' => $key, 'label' => $label, 'path' => $path, 'icon' => $icon, 'active' => $key === $active, 'tab' => $tab,
                'tab_active' => $tab && $key === $section];
        }
        return $out;
    }

    public function home(): void
    {
        $user = Auth::user();
        if ($user !== null && !isset($_GET['pular']) && !$this->onboarded((int)$user->id)) {
            redirect(app_url('/boas-vindas'));
            return;
        }
        $seen = self::seenAt();
        $this->page('home', null, 'pages/home', 'Início', function (User $u, array $link) use ($seen): array {
            try {
                $feed = array_slice((new ResidentFeed())->notifications($link), 0, 5);
            } catch (\Throwable) {
                $feed = [];
            }
            return ['summary' => $this->portal->summary($link), 'feed' => $feed, 'seenAt' => $seen];
        });
    }

    /** GET /boas-vindas?passo=1..4 — primeiro acesso: foto e dicas (revisão de 15/11, igual ao app de celular). */
    public function welcome(): void
    {
        $step = max(1, min(self::WELCOME_STEPS, (int)($_GET['passo'] ?? 1)));
        $this->page('welcome', null, 'pages/welcome', 'Boas-vindas', fn (User $u, array $link): array => ['step' => $step, 'steps' => self::WELCOME_STEPS,
            'hasPhoto' => (new ResidentProfile())->status((int)$u->id)['has_photo']]);
    }

    /** POST /boas-vindas — conclui ou pula as boas-vindas; não volta a aparecer. */
    public function finishWelcome(array $data): void
    {
        $this->action(null, '/', $data, function (User $u): string {
            (new ResidentProfile())->complete((int)$u->id);
            return 'Tudo pronto! Este é o seu aplicativo.';
        }, '/boas-vindas');
    }

    /** GET /perfil/foto — a foto da própria conta (nunca de outra pessoa). */
    public function photo(): void
    {
        $user = Auth::user();
        $file = $user !== null ? (new ResidentProfile())->photo((int)$user->id) : null;
        if ($file === null) {
            http_response_code(404);
            return;
        }
        \Source\Controllers\Api\V1\ResidentApp::sendPhoto($file);
    }

    /** POST /perfil/foto — envia ou troca a foto; volta para ?voltar=boas-vindas quando veio do primeiro acesso. */
    public function uploadPhoto(array $data): void
    {
        $back = ($data['voltar'] ?? '') === 'boas-vindas' ? '/boas-vindas?passo=1' : '/perfil';
        $this->action(null, $back, $data, function (User $u): string {
            (new ResidentProfile())->storePhoto((int)$u->id, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : []);
            return 'Foto salva.';
        });
    }

    /** POST /perfil/foto/remover */
    public function deletePhoto(array $data): void
    {
        $this->action(null, '/perfil', $data, function (User $u): string {
            (new ResidentProfile())->deletePhoto((int)$u->id);
            return 'Foto removida.';
        });
    }

    private const WELCOME_STEPS = 4;

    private function onboarded(int $userId): bool
    {
        try {
            return (new ResidentProfile())->status($userId)['completed'];
        } catch (\Throwable) {
            return true; // sem a tabela (migration pendente) o app abre normalmente
        }
    }

    /** GET /resolva-facil — atalhos dos serviços do condomínio (revisão de 15/11). */
    public function resolve(): void
    {
        $this->page('resolve', null, 'pages/resolve', 'Resolva fácil', fn (User $u, array $link): array => []);
    }

    /** GET /perfil — dados da pessoa, segurança, instalar e sair (revisão de 15/11). */
    public function profile(): void
    {
        $this->page('profile', null, 'pages/profile', 'Perfil', fn (User $u, array $link): array => ['profile' => $this->portal->profile($link, $u)]);
    }

    public function charges(): void
    {
        if (isset($_GET['parcial'])) {
            $this->chargesPage();
            return;
        }
        $this->page('charges', 'boletos', 'pages/charges', 'Boletos', function (User $u, array $link): array {
            $statement = $this->portal->charges($link);
            return ['statement' => $statement, 'more' => $this->moreCharges($link, $statement['from'])];
        });
    }

    /** GET /boletos?parcial=1&de=&ate= — próximas contas da rolagem infinita (só as linhas, sem o layout). */
    private function chargesPage(): void
    {
        $context = $this->context('boletos');
        if ($context === null) {
            return;
        }
        [, $link] = $context;
        try {
            $statement = $this->portal->charges($link, $_GET['de'] ?? null, $_GET['ate'] ?? null);
        } catch (\Throwable) {
            http_response_code(400);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->view->render('partials/charge-lines', ['lines' => $statement['lines'], 'more' => $this->moreCharges($link, $statement['from'])]);
    }

    /** Endereço da próxima janela (6 meses antes) ou null quando não há conta mais antiga. */
    private function moreCharges(array $link, string $from): ?string
    {
        if (!$this->portal->hasChargesBefore($link, $from)) {
            return null;
        }
        [$de, $ate] = ResidentPortal::previousWindow($from);
        return app_url('/boletos') . '?parcial=1&de=' . $de . '&ate=' . $ate;
    }

    /** GET /boletos/{id} — uma conta: pagar (boleto, linha, Pix) ou ver o recibo. */
    public function charge(array $data): void
    {
        $this->page('charges', 'boletos', 'pages/charge', 'Conta', fn (User $u, array $link): array => ['entry' => $this->portal->entry($link, (int)($data['id'] ?? 0))]);
    }

    /** GET /boletos/{id}/recibo — recibo para imprimir ou salvar em PDF (conta paga). */
    public function receipt(array $data): void
    {
        $this->printable('boletos', function (array $link) use ($data): array {
            try {
                return ['template' => 'pages/receipt', 'title' => 'Recibo', 'data' => ['receipt' => $this->portal->receipt($link, (int)($data['id'] ?? 0))]];
            } catch (DomainException $error) {
                $this->message->warning($error->getMessage())->flash();
                redirect(app_url('/boletos/' . (int)($data['id'] ?? 0)));
                return [];
            }
        });
    }

    /** GET /assembleias/{id}/edital — edital de convocação para imprimir ou salvar em PDF. */
    public function notice(array $data): void
    {
        $this->printable('assemblies', fn (array $link): array => ['template' => 'pages/notice', 'title' => 'Edital de convocação',
            'data' => ['assembly' => $this->portal->assembly($link, (int)($data['id'] ?? 0))]]);
    }

    /** GET /avisos — sininho: o que pede atenção; ao abrir, tudo passa a contar como visto. */
    public function notifications(): void
    {
        $seen = self::seenAt();
        $this->page('notifications', null, 'pages/notifications', 'Avisos', function (User $u, array $link) use ($seen): array {
            $items = (new ResidentFeed())->notifications($link);
            self::markSeen();
            return ['items' => $items, 'seenAt' => $seen];
        });
    }

    /** GET /buscar?q= — busca no que o morador pode ver. */
    public function search(): void
    {
        $query = is_string($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '';
        $this->page('search', null, 'pages/search', 'Buscar', fn (User $u, array $link): array => ['query' => $query,
            'results' => (new ResidentFeed())->search($link, $query, self::menu($link['features'], ''))]);
    }

    /** Última vez que o morador abriu os avisos (cookie do navegador, só uma data). */
    public static function seenAt(): ?string
    {
        $value = $_COOKIE['app_seen'] ?? '';
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
    }

    private static function markSeen(): void
    {
        if (!headers_sent()) {
            setcookie('app_seen', date('Y-m-d H:i:s'), ['expires' => time() + 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
        }
    }

    /** Página para imprimir (recibo, edital): sem menu, com o botão de imprimir. */
    private function printable(string $feature, callable $build): void
    {
        $context = $this->context($feature);
        if ($context === null) {
            return;
        }
        try {
            $page = $build($context[1]);
        } catch (RegistryNotFound) {
            $this->message->warning('Não encontrado.')->flash();
            redirect(app_url('/'));
            return;
        }
        if ($page === []) {
            return;
        }
        echo $this->view->render($page['template'], $page['data'] + [
            'head' => $this->seo->render($page['title'] . ' · Connect Morador', 'Aplicativo do morador da Connect.', app_url('/'), themeApp('/assets/images/icon-512.png'), false),
            'title' => $page['title'], 'user' => $context[0], 'link' => $context[1],
        ]);
    }

    /** GET /cnd — certidão de débitos da unidade (etapa 5 de 15/11); a mais nova fica no topo. */
    public function certificates(): void
    {
        $this->page('certificates', 'boletos', 'pages/certificates', 'CND', fn (User $u, array $link): array => [
            'certificates' => (new ResidentCertificates())->list($link)]);
    }

    /** POST /cnd — emite a certidão de hoje (mesma regra e código de verificação do ERP). */
    public function issueCertificate(array $data): void
    {
        $this->action('boletos', '/cnd', $data, function (User $u, array $link): string {
            $issued = (new ResidentCertificates())->issue($u, $link);
            return $issued['result'] === 'clear' ? 'Certidão emitida: nada consta.' : 'Certidão emitida. Ela mostra as contas vencidas em aberto.';
        });
    }

    /** GET /cnd/{id} — o PDF da certidão (só da unidade do vínculo). */
    public function certificatePdf(array $data): void
    {
        $context = $this->context('boletos');
        if ($context === null) {
            return;
        }
        $id = (int)($data['id'] ?? 0);
        try {
            $pdf = (new ResidentCertificates())->pdf($context[1], $id);
        } catch (RegistryNotFound | ResidentFeatureDisabled) {
            $this->message->warning('Certidão não encontrada.')->flash();
            redirect(app_url('/cnd'));
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certidao-de-debitos-' . $id . '.pdf"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
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
        $this->page('accountability', 'accountability', 'pages/accountability', 'Prestação de contas', fn (User $u, array $link): array => ['publications' => $this->portal->accountability($link)]);
    }

    public function announcements(): void
    {
        $this->page('announcements', 'announcements', 'pages/announcements', 'Comunicados', fn (User $u, array $link): array => ['announcements' => $this->portal->announcements($link)]);
    }

    public function reservations(): void
    {
        $this->page('reservations', 'reservations', 'pages/reservations', 'Reservas', fn (User $u, array $link): array => [
            'areas' => $this->portal->commonAreas($link), 'mine' => $this->portal->reservations($link),
            'tab' => ($_GET['aba'] ?? '') === 'minhas' ? 'minhas' : 'nova',
            'filter' => in_array($_GET['filtro'] ?? '', ['gratuitos', 'taxa'], true) ? $_GET['filtro'] : 'todos']);
    }

    /** Volta para o calendário da área quando o pedido falha (só id e data validados). */
    private static function areaBack(array $data): ?string
    {
        $area = filter_var($data['common_area_id'] ?? null, FILTER_VALIDATE_INT);
        if (empty($data['from_calendar']) || $area === false || $area === null) {
            return null;
        }
        $day = ResidentPortal::date($data['reserved_on'] ?? $data['desired_on'] ?? null);
        return '/reservas/area/' . (int)$area . ($day !== null ? '?dia=' . $day : '');
    }

    /** GET /reservas/area/{id}?mes=AAAA-MM&dia=AAAA-MM-DD — calendário do mês da área, horários tomados do dia e o pedido. */
    public function reservationArea(array $data): void
    {
        $this->page('reservations', 'reservations', 'pages/reservation-area', 'Reservas', function (User $u, array $link) use ($data): array {
            $id = (int)($data['id'] ?? 0);
            $area = null;
            foreach ($this->portal->commonAreas($link) as $a) {
                if ($a['id'] === $id) {
                    $area = $a;
                }
            }
            if ($area === null) {
                throw new RegistryNotFound();
            }
            $today = date('Y-m-d');
            $day = ResidentPortal::date($_GET['dia'] ?? null);
            $month = is_string($_GET['mes'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes']) === 1 ? $_GET['mes'] : ($day !== null ? substr($day, 0, 7) : substr($today, 0, 7));
            $availability = $this->portal->areaAvailability($link, $id, $month);
            $calendar = ResidentPortal::calendar($area, $availability['busy'], $month, $today);
            return ['area' => $area, 'month' => $month, 'calendar' => $calendar, 'today' => $today,
                'day' => $day !== null && isset($calendar[$day]) ? $day : null,
                'taken' => array_values(array_filter($availability['busy'], static fn (array $b): bool => $b['date'] === $day))];
        });
    }

    public function reserve(array $data): void
    {
        $this->action('reservations', '/reservas?aba=minhas', $data, function (User $u, array $link) use ($data): string {
            $saved = $this->portal->requestReservation($u, $link, self::only($data, ['common_area_id', 'reserved_on', 'starts_time', 'ends_time', 'notes']));
            return ($saved['status'] ?? '') === 'confirmed' ? 'Reserva confirmada.' : 'Pedido de reserva enviado. Você recebe a resposta da administração.';
        }, self::areaBack($data));
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
        $this->action('reservations', '/reservas?aba=minhas', $data, function (User $u, array $link) use ($data): string {
            $this->portal->joinWaitlist($u, $link, self::only($data, ['common_area_id', 'desired_on', 'notes']));
            return 'Você entrou na lista de espera. Avisamos se a data liberar.';
        }, self::areaBack($data));
    }

    public function occurrences(): void
    {
        $page = max(1, (int)($_GET['pagina'] ?? 1));
        $kind = in_array($_GET['tipo'] ?? null, ['chamado', 'ocorrencia'], true) ? $_GET['tipo'] : null;
        $this->page('occurrences', 'occurrences', 'pages/occurrences', 'Chamados', fn (User $u, array $link): array => [
            'tickets' => (new ResidentTickets())->list($u, $link, $page, $kind), 'kind' => $kind, 'open' => ($_GET['abrir'] ?? '') === '1']);
    }

    /** POST /ocorrencias — abre o chamado (a ocorrência da unidade vira chamado do Help Desk, ADR 0305). */
    public function reportOccurrence(array $data): void
    {
        $this->action('occurrences', '/ocorrencias', $data, function (User $u, array $link) use ($data): string {
            $ticket = (new ResidentTickets())->open($u, $link, self::only($data, ['subject', 'description', 'priority', 'occurred_on', 'kind']));
            return ($ticket['kind'] === 'chamado' ? 'Chamado ' . $ticket['number'] . ' aberto.' : 'Ocorrência ' . $ticket['number'] . ' registrada.')
                . ' Acompanhe a resposta da administração por aqui.';
        });
    }

    /** GET /ocorrencias/{id} — o chamado com a conversa. */
    public function ticket(array $data): void
    {
        $this->page('occurrences', 'occurrences', 'pages/ticket', 'Chamado', fn (User $u, array $link): array => ['ticket' => (new ResidentTickets())->show($u, $link, (int)($data['id'] ?? 0))]);
    }

    /** POST /ocorrencias/{id}/mensagens — o morador responde no chamado. */
    public function replyTicket(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $this->action('occurrences', '/ocorrencias/' . $id, $data, function (User $u, array $link) use ($data, $id): string {
            (new ResidentTickets())->reply($u, $link, $id, is_string($data['body'] ?? null) ? trim($data['body']) : '');
            return 'Mensagem enviada.';
        });
    }

    public function deliveries(): void
    {
        $this->page('deliveries', 'deliveries', 'pages/deliveries', 'Encomendas', fn (User $u, array $link): array => ['deliveries' => $this->portal->deliveries($link)]);
    }

    /** GET /minha-unidade/moradores — quem está ligado à unidade (etapa 4 de 15/11). ?novo=inquilino|morador abre o cadastro. */
    public function household(): void
    {
        $this->page('household', 'household', 'pages/household', 'Moradores', fn (User $u, array $link): array => [
            'household' => (new ResidentHousehold())->list($link),
            'chosen' => ['inquilino' => 'tenant', 'morador' => 'resident'][$_GET['novo'] ?? ''] ?? null]);
    }

    /** POST /minha-unidade/moradores — o proprietário cadastra inquilino ou morador; o inquilino, morador. */
    public function addHousehold(array $data): void
    {
        $this->action('household', '/minha-unidade/moradores', $data, function (User $u, array $link) use ($data): string {
            $person = (new ResidentHousehold())->add($u, $link, self::only($data, ['relationship', 'name', 'document', 'email', 'phone', 'starts_on']));
            return 'Cadastro feito: ' . $person['name'] . ', ' . mb_strtolower($person['relationship_label']) . '. A administração vê o cadastro no ERP.';
        });
    }

    /** POST /minha-unidade/moradores/{id}/bloquear — só o proprietário, só inquilino; depois oferece cadastrar o novo. */
    public function blockHousehold(array $data): void
    {
        $this->action('household', '/minha-unidade/moradores?novo=inquilino', $data, function (User $u, array $link) use ($data): string {
            $person = (new ResidentHousehold())->block($u, $link, (int)($data['id'] ?? 0));
            return 'Inquilino bloqueado: ' . $person['name'] . ' não acessa mais o aplicativo. Se quiser, cadastre o novo inquilino abaixo.';
        }, '/minha-unidade/moradores');
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
        $this->page('unit', null, 'pages/unit', 'Unidade', fn (User $u, array $link): array => ['petsVehicles' => $this->portal->petsVehicles($link)]);
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

    /** GET /seguranca — verificação em duas etapas opcional (ADR 0304). */
    public function security(): void
    {
        $this->page('security', null, 'pages/security', 'Segurança da conta', function (User $u): array {
            $status = (new ResidentTwoFactor())->status($u);
            if (isset($status['setup'])) {
                $status['setup']['qr'] = \Source\Support\QrCode::svg($status['setup']['uri'], 196, 'QR code para o aplicativo autenticador');
            }
            return ['twoFactor' => $status];
        });
    }

    /** POST /seguranca — action: confirm (ligar), disable (desligar) ou recovery (novos códigos). */
    public function updateSecurity(array $data): void
    {
        $context = $this->context(null);
        if ($context === null) {
            return;
        }
        $back = app_url('/seguranca');
        if (!csrf_verify($data)) {
            $this->message->error('Sessão expirada. Atualize a página e tente de novo.')->flash();
            redirect($back);
            return;
        }
        $action = (string)($data['action'] ?? '');
        $result = (new ResidentTwoFactor())->act($context[0], $action, $data['code'] ?? null);
        if (!$result['ok']) {
            $this->message->warning($result['message'])->flash();
            redirect($back);
            return;
        }
        if (isset($result['recovery_codes'])) {
            (new \Source\Core\Session())->set('auth2faRecoveryCodes', $result['recovery_codes']);
            $this->message->success($action === 'confirm' ? 'Verificação em duas etapas ligada.' : 'Novos códigos gerados. Os anteriores deixaram de valer.')->flash();
            redirect(app_url('/seguranca/codigos'));
            return;
        }
        $this->message->success('Verificação em duas etapas desligada. Você entra só com e-mail e senha.')->flash();
        redirect($back);
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
        $this->render('pages/codes', 'Códigos de recuperação', 'unit', $user, $all, $link, ['codes' => $codes === null ? [] : array_values(array_map('strval', (array)$codes))]);
    }

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode([
            'name' => 'Connect Morador', 'short_name' => 'Connect', 'lang' => 'pt-BR',
            'description' => 'Boletos, reservas, encomendas, visitantes e avisos do seu condomínio.',
            'start_url' => app_url('/'), 'scope' => app_url('/'), 'display' => 'standalone',
            'background_color' => '#0d2e27', 'theme_color' => '#f8faf9',
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
     * Sessão + vínculo + função; sem sessão ou sem vínculo vai para o login, sem a função mostra "não liberado".
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
            // Conta da equipe (ou vínculo encerrado): não entra no app; o login explica e pede a conta de morador.
            redirect(app_url('/login'));
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

    private function action(?string $feature, string $back, array $data, callable $run, ?string $failBack = null): void
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
            $failBack = null;
        } catch (RegistryValidationException $error) {
            $this->message->warning(implode(' ', array_unique(array_values($error->errors))))->flash();
        } catch (RegistryNotFound) {
            $this->message->warning('Não encontrado.')->flash();
        } catch (InvalidArgumentException | DomainException $error) {
            $this->message->warning($error->getMessage())->flash();
        } catch (ResidentFeatureDisabled | ResidentNotAllowed $error) {
            $this->message->warning($error->getMessage())->flash();
        }
        redirect(app_url($failBack ?? $back));
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
            'unseen' => $this->unseen($link, $active),
            'photo' => $this->photoUrl($user, $link),
        ]);
    }

    /** Endereço da foto da conta para o avatar (com a data no fim, para trocar na hora), ou null. */
    private function photoUrl(User $user, ?array $link): ?string
    {
        if ($link === null) {
            return null;
        }
        try {
            $file = (new ResidentProfile())->photo((int)$user->id);
        } catch (\Throwable) {
            return null;
        }
        return $file !== null ? app_url('/perfil/foto') . '?v=' . (string)filemtime($file) : null;
    }

    /** Número no sininho; na própria página de avisos é zero. Erro aqui nunca derruba a página. */
    private function unseen(?array $link, string $active): int
    {
        if ($link === null || $active === 'notifications') {
            return 0;
        }
        try {
            return ResidentFeed::unseen((new ResidentFeed())->notifications($link), self::seenAt());
        } catch (\Throwable) {
            return 0;
        }
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
