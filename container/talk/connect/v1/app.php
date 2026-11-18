<?php
declare(strict_types=1);

/**
 * Connect Talk dentro do ERP (ADR 0324): front controller do host talk.<domínio>. O index.php do ERP inclui este
 * arquivo quando talk_host() e a rota não é do ERP (troca de ambiente). Veio do public/index.php do Talk separado
 * (repositório djalmamartins/talk, 0.22.0) com três trocas: o login e as permissões são os do ERP (Auth + talk.access),
 * a sessão é a do ERP (chaves talk_*), e o chamado e o cadastro vêm direto do ERP (LocalHelpdesk, LocalContactDirectory).
 */

use Source\Models\Auth;
use Source\Services\Environments;
use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Gateway\CloudApiGateway;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\TalkApp;

defined('CONF_DB_NAME') || exit;

$app = TalkApp::build();
$env = $app['env'];
$isLab = $env('ENV') === 'lab';

// verificação de saúde (Docker e monitoramento): banco respondendo e worker vivo; sem sessão
if (rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $hb = $app['db']->one("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM talk_settings WHERE name = 'worker_heartbeat'");
        $age = $hb ? (int) $hb['age'] : null;
        echo json_encode(['ok' => true, 'db' => 'ok', 'worker' => ['ok' => $age !== null && $age <= 90, 'seconds_since' => $age]]);
    } catch (\Throwable) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'db' => 'erro']);
    }
    exit;
}

// Sessão do ERP (já aberta pelo index.php); as chaves do Talk têm o prefixo talk_.
if (empty($_SESSION['talk_csrf'])) {
    $_SESSION['talk_csrf'] = bin2hex(random_bytes(16));
}

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

$json = static function ($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};
// envio acima do post_max_size: o PHP descarta o corpo inteiro (sem $_POST/$_FILES); avisa em vez de "sessão expirada"
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
    $json(['error' => 'Arquivo maior que 16 MB.'], 413);
}
$body = static fn (): array => json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
// Quem entra é o usuário do ERP com talk.access; o perfil do Talk (setores e papel) vem de talk_users/talk_staff.
$sessionOk = static function () use ($app): bool {
    $profile = $app['erp_login']->profileFor(Auth::user());
    if ($profile === null) {
        unset($_SESSION['talk_user_id'], $_SESSION['talk_user_name']);
        return false;
    }
    $_SESSION['talk_user_id'] = (int) $profile['id'];
    $_SESSION['talk_user_name'] = (string) $profile['name'];
    return true;
};
// Para onde mandar quem não está logado: o login do ERP (depois, o alternador de ambientes traz de volta).
$loginUrl = static fn (): string => Environments::url('erp', '/login');
$user = static function () use ($app, $json, $sessionOk) {
    if (!$sessionOk()) {
        $json(['error' => 'Faça login.'], 401);
    }
    return $app['staff']->find((int) $_SESSION['talk_user_id']);
};
$csrf = static function () use ($json): void {
    $t = $_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['talk_csrf'], (string) $t)) {
        $json(['error' => 'Sessão expirada, recarregue a página.'], 403); // 419 não existe no Apache (vira 500)
    }
};
// arquivo enviado pelo formulário (campo "file"): [conteúdo, mime declarado, nome]
$upload = static function () use ($json): array {
    $f = $_FILES['file'] ?? null;
    if (!$f || is_array($f['error'])) {
        $json(['error' => 'Escolha um arquivo.'], 422);
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $json(['error' => 'Arquivo maior que 16 MB.'], 422);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $json(['error' => 'Falha no envio do arquivo.'], 422);
    }
    return [(string) file_get_contents($f['tmp_name']), (string) $f['type'], (string) $f['name']];
};
// versão dos arquivos de tela: muda a cada atualização, e o navegador não fica com CSS/JS velho
defined('TALK_ASSET_V') || define('TALK_ASSET_V', (string) max(array_map('filemtime', array_merge([__FILE__], glob(__DIR__ . '/assets/*.css') ?: [], glob(__DIR__ . '/assets/*.js') ?: []))));
$view = static function (string $name, array $vars = []) use ($app): never {
    $vars['csrf'] ??= $_SESSION['talk_csrf'];
    // o menu lateral mostra "Conexão WhatsApp" só para o supervisor
    $sm = !empty($_SESSION['talk_user_id']) ? $app['staff']->find((int) $_SESSION['talk_user_id']) : null;
    $vars['isSupervisor'] = $sm !== null && $sm->supervisor;
    $vars['canBulk'] = $sm !== null && ($sm->supervisor || $sm->managerSectors);
    $vars['roleLabel'] = $sm === null ? '' : ($sm->supervisor ? 'Supervisor' : ($sm->managerSectors ? 'Gerente de setor' : 'Atendente'));
    $vars['userEmail'] = $sm !== null ? (string) ($app['db']->one('SELECT email FROM talk_users WHERE id = ?', [$sm->userId])['email'] ?? '') : '';
    // alternador de ambientes do ERP (o mesmo da sidebar do ERP), com o Talk marcado
    $vars['systems'] = Environments::menu(Auth::user(), 'talk');
    extract($vars);
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . "/views/$name.php";
    exit;
};
// no laboratório o envio é instantâneo: processa a fila ao final da requisição
if ($isLab) {
    register_shutdown_function(static function () use ($app): void {
        try {
            $app['conversations']->triage();
            $app['schedules']->runDue();
            $app['campaigns']->runDue();
            $app['worker']->runOnce();
            $app['worker']->fetchMedia();
        } catch (\Throwable $e) {
            error_log('[talk] worker: ' . $e->getMessage());
        }
    });
}

try {
    // ---------------------------------------------------------------- webhooks (sem login)
    if (preg_match('#^/webhook/(\d+)$#', $path, $m)) {
        $channel = (int) $m[1];
        if ($method === 'GET') {
            $gw = $app['gateways']->forChannel($channel);
            $challenge = $gw instanceof CloudApiGateway ? $gw->verifySubscription($_GET) : null;
            http_response_code($challenge === null ? 403 : 200);
            echo $challenge ?? 'forbidden';
            exit;
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $r = $app['webhook']->handle($channel, (string) file_get_contents('php://input'), $headers);
        $json(['processed' => $r['processed']], $r['status']);
    }

    // ---------------------------------------------------------------- login
    if ($path === '/login') {
        // O login é o do ERP (ADR 0324). Logado no ERP mas sem acesso ao Talk: explica em vez de voltar em círculo.
        if (Auth::user() !== null && !$sessionOk()) {
            $view('login', ['error' => $app['erp_login']->deniedReason(Auth::user()), 'isLab' => $isLab, 'erpUrl' => Environments::url('erp', '/dashboard')]);
        }
        header('Location: ' . (Auth::user() !== null ? '/' : $loginUrl()));
        exit;
    }
    if ($path === '/logout' && $method === 'POST') {
        $csrf();
        Auth::logout();
        header('Location: ' . $loginUrl());
        exit;
    }
    if ($path === '/') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('inbox', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/contatos') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('contacts', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/agendamentos') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('schedules', ['isLab' => $isLab, 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/envios') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $me = $app['staff']->find((int) $_SESSION['talk_user_id']);
        if (!$me->supervisor && !$me->managerSectors) {
            header('Location: /');
            exit;
        }
        $view('campaigns', ['isLab' => $isLab, 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/configuracoes') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['talk_user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('settings', ['isLab' => $isLab, 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/equipe') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['talk_user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('team', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/conexoes') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['talk_user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('channels', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/painel') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('reports', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }
    if ($path === '/fila') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('queue', ['isLab' => $isLab, 'csrf' => $_SESSION['talk_csrf'], 'userName' => $_SESSION['talk_user_name'] ?? '']);
    }

    // ---------------------------------------------------------------- tempo real
    if ($path === '/events') {
        $me = $user();
        session_write_close(); // não travar a sessão enquanto o stream está aberto
        $app['queue']->touch($me->userId); // presença: conectado = online (o stream reconecta a cada ~25 s)
        $last = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last'] ?? 0);
        if ($last === 0) {
            $last = (int) ($app['db']->one('SELECT COALESCE(MAX(id),0) m FROM talk_events')['m']);
        }
        // laboratório: sem worker, o próprio stream faz a triagem da Jack e os envios a cada ~2 s
        $app['sse']->stream($me, $last, tick: $isLab ? static function () use ($app): void {
            $app['conversations']->triage();
            $app['worker']->runOnce();
        } : null);
        exit;
    }

    // ---------------------------------------------------------------- arquivos (com conferência de acesso)
    if (preg_match('#^/media/(\d+)$#', $path, $m)) {
        $me = $user();
        session_write_close();
        $file = $app['inbox']->mediaFor($me, (int) $m[1]);
        $abs = $app['media']->absolute((string) $file['media_path']);
        $mime = (string) $file['media_mime'];
        $inline = preg_match('#^(image/(jpeg|png|webp)|audio/|video/|application/pdf$)#', $mime) === 1;
        $name = (string) ($file['media_name'] ?: basename($abs));
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        if ($mime !== 'application/pdf') { // o leitor de PDF do navegador não abre com sandbox
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . "\"; filename*=UTF-8''" . rawurlencode($name));
        readfile($abs);
        exit;
    }

    // ---------------------------------------------------------------- API
    if (str_starts_with($path, '/api/')) {
        $me = $user();
        if ($method !== 'GET') {
            $csrf();
        }
        $svc = $app['conversations'];

        if ($path === '/api/me') {
            $names = [];
            foreach ($app['db']->all('SELECT id, name FROM talk_users') as $r) {
                $names[(int) $r['id']] = $r['name'];
            }
            $json([
                'user' => ['id' => $me->userId, 'name' => $_SESSION['talk_user_name'] ?? '', 'supervisor' => $me->supervisor,
                    'agent_sectors' => $me->agentSectors, 'manager_sectors' => $me->managerSectors],
                'sectors' => $app['db']->all('SELECT id, name, menu_key, color FROM talk_sectors WHERE active = 1 ORDER BY menu_key'),
                'users' => $names,
                // para "Trocar atendente": quem atende em cada setor
                'staff' => $app['db']->all(
                    "SELECT s.user_id, u.name, s.sector_id, s.role FROM talk_staff s JOIN talk_users u ON u.id = s.user_id
                      WHERE u.active = 1 ORDER BY u.name"
                ),
            ]);
        }
        if ($path === '/api/notifications') {
            $json($app['notifications']->for($me));
        }
        if ($path === '/api/conversations') {
            $sector = isset($_GET['sector']) && $_GET['sector'] !== '' ? (int) $_GET['sector'] : null;
            $tag = isset($_GET['tag']) && $_GET['tag'] !== '' ? (int) $_GET['tag'] : null;
            $json($app['inbox']->list($me, (string) ($_GET['tab'] ?? 'pending'), $sector, tagId: $tag));
        }
        if ($path === '/api/jack/stats') {
            if (!$me->supervisor && $me->managerSectors === []) {
                $json(['error' => 'Só supervisor e gerentes veem os acertos da Jack.'], 403);
            }
            $json($app['jack']->stats(max(1, min(365, (int) ($_GET['days'] ?? 30)))));
        }
        if ($path === '/api/contacts') {
            $json($app['contactbook']->list($me, $_GET));
        }
        if (preg_match('#^/api/contacts/(\d+)(?:/(rename|block|unblock))?$#', $path, $m)) {
            $cb = $app['contactbook'];
            $cid = (int) $m[1];
            $action = $m[2] ?? null;
            if ($action === null && $method === 'GET') {
                $json($cb->show($me, $cid));
            }
            if ($method === 'POST') {
                $b = $body();
                $json(match ($action) {
                    'rename' => $cb->rename($me, $cid, (string) ($b['name'] ?? '')),
                    'block' => $cb->block($me, $cid, isset($b['reason']) ? (string) $b['reason'] : null),
                    'unblock' => $cb->unblock($me, $cid),
                    default => throw new DomainError('Ação inválida.', 404),
                });
            }
        }
        if ($path === '/api/templates') {
            $json($method === 'POST' ? $app['campaigns']->saveTemplate($me, $body()) : $app['campaigns']->templates($me));
        }
        if ($path === '/api/templates/sync' && $method === 'POST') {
            $json($app['campaigns']->syncTemplates($me, (int) ($body()['channel_id'] ?? 0)));
        }
        if (preg_match('#^/api/templates/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['campaigns']->deleteTemplate($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['campaigns']->saveTemplate($me, $body(), (int) $m[1]));
        }
        if ($path === '/api/campaigns') {
            $json($method === 'POST' ? $app['campaigns']->save($me, $body()) : $app['campaigns']->list($me));
        }
        if ($path === '/api/campaigns/preview' && $method === 'POST') {
            $json($app['campaigns']->preview($me, $body()));
        }
        if (preg_match('#^/api/campaigns/(\d+)(?:/(start|pause|resume|cancel|recipients))?$#', $path, $m)) {
            $cid = (int) $m[1];
            $cs = $app['campaigns'];
            $json(match (true) {
                ($m[2] ?? '') === 'recipients' => $cs->recipients($me, $cid, (string) ($_GET['status'] ?? '')),
                $method === 'GET' => $cs->one($me, $cid),
                ($m[2] ?? '') === 'start' => $cs->start($me, $cid),
                ($m[2] ?? '') === 'pause' => $cs->pause($me, $cid),
                ($m[2] ?? '') === 'resume' => $cs->resume($me, $cid),
                ($m[2] ?? '') === 'cancel' => $cs->cancel($me, $cid),
                default => $cs->save($me, $body(), $cid),
            });
        }
        if ($path === '/api/schedules') {
            $json($method === 'POST' ? $app['schedules']->save($me, $body())
                : ['items' => $app['schedules']->list($me, $_GET), 'counts' => $app['schedules']->counts($me)]);
        }
        if (preg_match('#^/api/schedules/(\d+)(?:/(cancel))?$#', $path, $m)) {
            $sid = (int) $m[1];
            $json(match (true) {
                $method === 'GET' => $app['schedules']->one($me, $sid),
                ($m[2] ?? '') === 'cancel' => $app['schedules']->cancel($me, $sid),
                default => $app['schedules']->save($me, $body(), $sid),
            });
        }
        if ($path === '/api/settings/hours') {
            $json($method === 'POST' ? $app['hours']->save($me, $body()) : $app['hours']->overview($me));
        }
        if ($path === '/api/settings/holidays' && $method === 'POST') {
            $json($app['hours']->addHoliday($me, $body()));
        }
        if ($path === '/api/settings/holidays/import' && $method === 'POST') {
            $json($app['hours']->importNational($me, (int) ($body()['year'] ?? 0)));
        }
        if (preg_match('#^/api/settings/holidays/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            $json($app['hours']->deleteHoliday($me, (int) $m[1]));
        }
        if ($path === '/api/me/password' && $method === 'POST') {
            $json(['error' => 'A senha é a do ERP: troque em Minha conta, no ERP.'], 409);
        }
        if ($path === '/api/team/users') {
            $json($method === 'POST' ? $app['team']->saveUser($me, $body()) : $app['team']->users($me));
        }
        if (preg_match('#^/api/team/users/(\d+)(?:/(activate|deactivate))?$#', $path, $m)) {
            $uid = (int) $m[1];
            if ($method === 'GET') {
                $json($app['team']->user($me, $uid));
            }
            $r = match ($m[2] ?? '') {
                '' => $app['team']->saveUser($me, $body(), $uid),
                'activate' => $app['team']->setActive($me, $uid, true),
                'deactivate' => $app['team']->setActive($me, $uid, false),
            };
            if ($uid === $me->userId) {
                $_SESSION['talk_user_name'] = $r['name'];
            }
            $json($r);
        }
        if ($path === '/api/team/sectors') {
            $json($method === 'POST' ? $app['team']->saveSector($me, $body()) : $app['team']->sectors($me));
        }
        if (preg_match('#^/api/team/sectors/(\d+)(?:/(activate|deactivate))?$#', $path, $m)) {
            $sid = (int) $m[1];
            $json(match (true) {
                $method === 'GET' => $app['team']->sector($me, $sid),
                ($m[2] ?? '') === 'activate' => $app['team']->setSectorActive($me, $sid, true),
                ($m[2] ?? '') === 'deactivate' => $app['team']->setSectorActive($me, $sid, false),
                default => $app['team']->saveSector($me, $body(), $sid),
            });
        }
        if ($path === '/api/team/audit') {
            $json($app['team']->auditLog($me, (int) ($_GET['limit'] ?? 50)));
        }
        if ($path === '/api/channels') {
            $json($method === 'POST' ? $app['channels']->save($me, $body()) : $app['channels']->list($me));
        }
        if (preg_match('#^/api/channels/(\d+)(?:/(connect|state|logout|default|toggle|delete|simulate-scan))?$#', $path, $m)) {
            $ch = $app['channels'];
            $cid = (int) $m[1];
            $action = $m[2] ?? '';
            if ($action === '' && $method === 'GET') {
                $json($ch->one($me, $cid));
            }
            if ($method !== 'POST') {
                $json(['error' => 'Use POST.'], 405);
            }
            if ($action === 'delete') {
                $ch->delete($me, $cid);
                $json(['ok' => true]);
            }
            $json(match ($action) {
                '' => $ch->save($me, $body(), $cid),
                'connect' => $ch->connect($me, $cid),
                'state' => $ch->refresh($me, $cid),
                'logout' => $ch->logout($me, $cid),
                'default' => $ch->setDefault($me, $cid),
                'toggle' => $ch->toggle($me, $cid),
                'simulate-scan' => $ch->simulateScan($me, $cid),
            });
        }
        if ($path === '/api/reports') {
            $json($app['reports']->summary($me, $_GET));
        }
        if ($path === '/api/reports/export.csv') {
            $csvBody = $app['reports']->exportCsv($me, $_GET);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="atendimentos-' . preg_replace('/[^0-9-]/', '', (string) ($_GET['from'] ?? '')) . '-' . preg_replace('/[^0-9-]/', '', (string) ($_GET['to'] ?? '')) . '.csv"');
            header('Cache-Control: private, no-store'); // ADR 0391
            echo $csvBody;
            exit;
        }
        if ($path === '/api/queue') {
            $json($app['queue']->overview($me));
        }
        if ($path === '/api/tags') {
            $json($method === 'POST' ? $app['contacts']->saveTag($me, $body()) : $app['contacts']->tags());
        }
        if (preg_match('#^/api/tags/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['contacts']->deleteTag($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['contacts']->saveTag($me, $body(), (int) $m[1]));
        }
        if (preg_match('#^/api/conversations/(\d+)/contact-notes/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            $app['contacts']->deleteNote($me, (int) $m[1], (int) $m[2]);
            $json(['ok' => true]);
        }
        if ($path === '/api/quick-messages') {
            $json($method === 'POST' ? $app['quick']->save($me, $body()) : $app['quick']->available($me));
        }
        if (preg_match('#^/api/quick-messages/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['quick']->delete($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['quick']->save($me, $body(), (int) $m[1]));
        }
        if ($path === '/api/search') {
            $json($app['inbox']->search($me, $_GET));
        }
        if (preg_match('#^/api/conversations/(\d+)(?:/(\w+))?$#', $path, $m)) {
            $id = (int) $m[1];
            $action = $m[2] ?? null;
            $b = $method === 'POST' ? $body() : [];
            switch ($action) {
                case null:
                    $conv = $svc->get($id);
                    $messages = $app['inbox']->messages($me, $id);
                    $contact = $app['db']->one('SELECT id, name, phone, erp_person_id, erp_snapshot FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                    $json(['conversation' => $conv, 'contact' => $contact, 'messages' => $messages,
                        'can_act' => $app['policy']->canAct($me, $conv)]);
                case 'accept':
                    $json($svc->accept($id, $me));
                case 'status':
                    $json($svc->setStatus($id, $me, (string) ($b['status'] ?? '')));
                case 'priority':
                    $json($svc->setPriority($id, $me, (string) ($b['priority'] ?? '')));
                case 'panel':
                    $json($app['contacts']->panel($me, $id));
                case 'history':
                    $json($app['contacts']->history($me, $id, (int) ($_GET['pagina'] ?? 1)));
                case 'tags':
                    $json($app['contacts']->setContactTags($me, $id, (array) ($b['tag_ids'] ?? [])));
                case 'contactnotes':
                    $json($app['contacts']->addNote($me, $id, (string) ($b['body'] ?? '')));
                case 'attach':
                    [$bytes, $mime, $fname] = $upload();
                    $json(['message_id' => $svc->sendFile($id, $me, $bytes, $mime, $fname, isset($_POST['caption']) ? (string) $_POST['caption'] : null,
                        ($_POST['sign'] ?? '1') !== '0' ? explode(' (', (string) ($_SESSION['talk_user_name'] ?? ''))[0] : null)]);
                case 'reply':
                    $json(['message_id' => $svc->reply($id, $me, (string) ($b['text'] ?? ''), ($b['sign'] ?? true) ? explode(' (', (string) ($_SESSION['talk_user_name'] ?? ''))[0] : null)]);
                case 'transfer':
                    $json($svc->transfer(
                        $id,
                        $me,
                        (int) ($b['sector_id'] ?? 0),
                        isset($b['note']) ? (string) $b['note'] : null,
                        !empty($b['user_id']) ? (int) $b['user_id'] : null,
                        !isset($b['keep_history']) || (bool) $b['keep_history'],
                    ));
                case 'close':
                    $json($svc->close($id, $me, $b['resolution'] ?? null));
                case 'ticket':
                    $json($svc->openTicketNow($id, $me, (string) ($b['subject'] ?? ''), (string) ($b['description'] ?? '')));
                case 'notes':
                    $json(['message_id' => $svc->addNote($id, $me, (string) ($b['note'] ?? ''))]);
                case 'read':
                    $svc->markRead($id, $me);
                    $json(['ok' => true]);
            }
        }
        $json(['error' => 'Rota não encontrada.'], 404);
    }

    // ---------------------------------------------------------------- simulador (só laboratório)
    if ($isLab && str_starts_with($path, '/lab/')) {
        $user();
        if ($path === '/lab/simulate' && $method === 'POST') {
            $csrf();
            $b = $body();
            $text = trim((string) ($b['text'] ?? ''));
            $file = null;
            if (!empty($_FILES['file'])) {
                [$bytes, $mime, $fname] = $upload();
                $file = $app['media']->save($bytes, $mime, $fname);
            }
            if ($text === '' && $file === null) {
                $json(['error' => 'Escreva a mensagem.'], 422);
            }
            // número de destino: o simulado escolhido (ex.: o do plantão da Manutenção) ou o primeiro simulado
            $channel = (int) ($app['db']->one("SELECT id FROM talk_channels WHERE kind = 'lab' AND id = ?", [(int) ($b['channel_id'] ?? 0)])
                ?? $app['db']->one("SELECT id FROM talk_channels WHERE kind = 'lab' ORDER BY is_default DESC, id LIMIT 1"))['id'];
            $pid = 'SIM-' . bin2hex(random_bytes(8));
            $type = $file['type'] ?? 'text';
            $id = $app['conversations']->handleInbound($channel, new InboundMessage(
                $pid, (string) ($b['phone'] ?? '5531999990001'), $type,
                $type === 'document' ? $file['name'] : ($text !== '' ? $text : null), ($b['name'] ?? '') ?: null
            ));
            if ($file !== null) { // no laboratório o arquivo já está aqui: grava direto, sem "baixar do WhatsApp"
                $app['db']->run('UPDATE talk_messages SET media_path = ?, media_mime = ?, media_name = ?, media_size = ? WHERE provider_message_id = ?',
                    [$file['path'], $file['mime'], $file['name'], $file['size'], $pid]);
            }
            $json(['conversation_id' => $id]);
        }
        if ($path === '/lab/customer') {
            $phone = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? ''));
            $rows = $app['db']->all(
                "SELECT m.id, m.direction, m.type, m.body, m.media_path, m.media_name, m.ack, m.created_at, c.protocol FROM talk_messages m
                 JOIN talk_conversations c ON c.id = m.conversation_id JOIN talk_contacts ct ON ct.id = c.contact_id
                 WHERE ct.phone = ? AND m.direction <> 'internal' ORDER BY m.id DESC LIMIT 40",
                [\Source\Services\Talk\Support\Phone::normalize($phone)]
            );
            // o cliente só vê o que já foi enviado; "digitando…" enquanto houver mensagem na fila
            $rows = array_values(array_filter(array_reverse($rows), fn ($r) => $r['direction'] !== 'out' || $r['ack'] !== 'pending'));
            $typing = (bool) $app['db']->one(
                "SELECT o.id FROM talk_outbox o WHERE o.to_phone = ? AND o.status IN ('queued','sending')
                   AND (o.typing_seconds = 0 AND o.next_attempt_at > NOW()) LIMIT 1",
                [\Source\Services\Talk\Support\Phone::normalize($phone)]
            );
            $json(['messages' => $rows, 'typing' => $typing]);
        }
    }

    http_response_code(404);
    echo 'Não encontrado';
} catch (DomainError $e) {
    $json(['error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (\Throwable $e) {
    error_log('[talk] ' . $e);
    $json(['error' => $isLab ? $e->getMessage() : 'Erro interno.'], 500);
}
