<?php

namespace Source\Controllers\Studio;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Address;
use Source\Models\Brief\AppBrief;
use Source\Models\Post\Category;
use Source\Models\Post\Page;
use Source\Models\Post\Post;
use Source\Models\Faq\Channel;
use Source\Models\Faq\Question;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Notification\Notification;
use Source\Models\Notification\NotificationCategory;
use Source\Models\Notification\NotificationMessage;
use Source\Models\Notification\AuditLog;
use Source\Models\Proposal\Proposal;
use Source\Models\Proposal\ProposalResponse;
use Source\Models\Settings\Settings;
use Source\Models\Slide\AppSlides;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;
use Source\Models\User;
use Source\Support\Pager;
use Source\Support\Upload;
use Source\Support\Communication;
use Source\Support\Access as AccessControl;
use Source\Support\Email;
use Source\Support\Proposal\ProposalMailer;
use Source\Support\Proposal\ProposalPdf;
use Source\Core\Connect;

class Studio extends Controller
{
    private const VIEW_PATH = __DIR__ . "/../../../container/cms/studio/v1/";
    private const ADMIN_LEVEL = 5;

    public function __construct()
    {
        // Studio e Help Desk compartilham os mesmos componentes. A URL atual
        // define identidade, navegação e destinos sem duplicar a implementação.
        parent::__construct(self::VIEW_PATH);
        (new Access())->report();
        (new Online())->report();
    }

    public function root(): void
    {
        redirect(AccessControl::can("studio.access", Auth::user()) ? "/studio/dash" : "/studio/login");
    }

    public function login(?array $data): void
    {
        $helpDesk = $this->isHelpDeskRequest();
        if (AccessControl::can("studio.access", Auth::user())) {
            redirect($helpDesk ? "/helpdesk/tickets" : "/studio/dash");
        }

        if (!empty($data)) {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (!csrf_verify($data)) {
                echo json_encode(["message" => $this->message->error("Sessão expirada. Atualize a página e tente novamente.")->render()]);
                return;
            }
            if (request_limit("studio_login", 8, 300)) {
                echo json_encode(["message" => $this->message->warning("Muitas tentativas. Aguarde alguns minutos.")->render()]);
                return;
            }
            if (empty($data["email"]) || empty($data["password"])) {
                echo json_encode(["message" => $this->message->warning("Informe e-mail e senha.")->render()]);
                return;
            }

            $auth = new Auth();
            $login = $auth->login($data["email"], $data["password"], !empty($data["save"]), 1);
            if ($login === null && Auth::twoFactorPending()) {
                // Senha válida; a sessão só é elevada após o segundo fator.
                echo json_encode(["redirect" => url("/studio/login/2fa" . ($helpDesk ? "?next=helpdesk" : ""))]);
                return;
            }
            if (!$login) {
                echo json_encode(["message" => $auth->message()->render()]);
                return;
            }
            if (!AccessControl::can("studio.access", Auth::user())) {
                Auth::logout();
                echo json_encode(["message" => $this->message->warning("Sua conta não possui acesso a este ambiente.")->render()]);
                return;
            }
            echo json_encode(["redirect" => url($helpDesk ? "/helpdesk/tickets" : "/studio/dash")]);
            return;
        }

        echo $this->view->render("components/login/login", [
            "head" => $this->seo->render(
                "MovesOS - " . CONF_SITE_NAME,
                CONF_SITE_DESC,
                url($helpDesk ? "/helpdesk/login" : "/studio/login"),
                themeStudio("/assets/images/favicon.png", "default")
            ),
            "cookie" => filter_input(INPUT_COOKIE, "authEmail"),
            "adminBase" => $helpDesk ? "/helpdesk" : "/studio",
            "loginEnvironment" => $helpDesk ? "helpdesk" : "studio"
        ]);
    }

    public function logout(): void
    {
        $helpDesk = $this->isHelpDeskRequest();
        Auth::logout();
        redirect($helpDesk ? "/helpdesk/login" : "/studio/login");
    }

    public function dash(): void
    {
        $user = $this->guard("dashboard.view");
        echo $this->view->render("components/dash/home", $this->viewData("Dashboard", "dash", $user, [
            "pagesCount" => (new Page())->find()->count(),
            "postsCount" => (new Post())->find()->count(),
            "publishedCount" => (new Post())->find("status = :s", "s=post")->count(),
            "categoriesCount" => (new Category())->find()->count(),
            "usersCount" => (new User())->find()->count(),
            "recentPosts" => (new Post())->find()->order("id DESC")->limit(5)->fetch(true) ?: [],
            "recentUsers" => (new User())->find()->order("id DESC")->limit(4)->fetch(true) ?: [],
            "accessDays" => (new Access())->find()->order("created_at DESC")->limit(7)->fetch(true) ?: [],
            "onlineCount" => (new Online())->findByActive(true) ?: 0
        ]));
    }

    public function search(): void
    {
        $user = $this->guard();
        $term = trim(strip_tags((string)($_GET["q"] ?? "")));
        $like = "%{$term}%";
        $encodedLike = urlencode($like);

        echo $this->view->render("components/search/home", $this->viewData("Busca", "search", $user, [
            "term" => $term,
            "pages" => $term !== "" && $user->can("pages.manage") ? ((new Page())->find("title LIKE :q1 OR uri LIKE :q2", "q1={$encodedLike}&q2={$encodedLike}")->order("title")->limit(8)->fetch(true) ?: []) : [],
            "posts" => $term !== "" && $user->can("articles.manage") ? ((new Post())->find("title LIKE :q1 OR subtitle LIKE :q2", "q1={$encodedLike}&q2={$encodedLike}")->order("id DESC")->limit(8)->fetch(true) ?: []) : [],
            "categories" => $term !== "" && $user->can("articles.manage") ? ((new Category())->find("title LIKE :q1 OR description LIKE :q2", "q1={$encodedLike}&q2={$encodedLike}")->order("title")->limit(8)->fetch(true) ?: []) : [],
            "users" => $term !== "" && $user->can("users.manage") ? ((new User())->find("first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3", "q1={$encodedLike}&q2={$encodedLike}&q3={$encodedLike}")->order("first_name")->limit(8)->fetch(true) ?: []) : [],
            "proposals" => $term !== "" && $user->can("proposals.manage") ? ((new Proposal())->find("name LIKE :q1 OR email LIKE :q2 OR condominium LIKE :q3 OR protocol LIKE :q4", "q1={$encodedLike}&q2={$encodedLike}&q3={$encodedLike}&q4={$encodedLike}")->order("id DESC")->limit(8)->fetch(true) ?: []) : []
        ]));
    }

    public function proposals(?array $data): void
    {
        $user = $this->guard('proposals.manage');
        $search = trim((string)($_GET['search'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $terms = [];
        $params = [];
        if ($search !== '') {
            $terms[] = '(name LIKE :search OR email LIKE :search OR condominium LIKE :search OR protocol LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $statuses = ['new','contacted','qualified','proposal_sent','won','lost','archived'];
        if (in_array($status, $statuses, true)) {
            $terms[] = 'status = :status';
            $params['status'] = $status;
        }
        $query = (new Proposal())->find($terms ? implode(' AND ', $terms) : null, $params ? http_build_query($params) : null);
        $pager = new Pager(url('/studio/proposals/p/'));
        $pager->pager($query->count(), 12, (int)($data['page'] ?? 1));
        $pdo = Connect::getInstance();
        $stats = $pdo->query("SELECT COUNT(*) total, SUM(status='new') new_count, SUM(status IN ('contacted','qualified','proposal_sent')) progress_count, SUM(status='won') won_count FROM proposals")->fetch();
        echo $this->view->render('components/proposals/home', $this->viewData('Propostas', 'proposals', $user, [
            'proposals' => $query->order('created_at DESC')->limit($pager->limit())->offset($pager->offset())->fetch(true) ?: [],
            'paginator' => $pager->render(), 'stats' => $stats, 'search' => $search, 'status' => $status,
        ]));
    }

    public function proposal(array $data): void
    {
        $user = $this->guard('proposals.manage');
        $proposal = (new Proposal())->findById((int)($data['id'] ?? 0));
        if (!$proposal) { redirect('/studio/proposals'); return; }
        if (!empty($data['action'])) {
            if (!csrf_verify($data)) { $this->jsonMessage('Sessão expirada. Atualize a página.', 'error'); return; }
            if (in_array($data['action'], ['generate_pdf','send_pdf'], true)) {
                $template = in_array($data['template_type'] ?? '', ['syndic','administrator'], true) ? $data['template_type'] : 'syndic';
                $subject = trim(strip_tags((string)($data['subject'] ?? '')));
                $introduction = trim(strip_tags((string)($data['introduction'] ?? '')));
                $scope = trim(strip_tags((string)($data['scope'] ?? '')));
                $commercial = trim(strip_tags((string)($data['commercial_terms'] ?? '')));
                $payment = trim(strip_tags((string)($data['payment_terms'] ?? '')));
                $notes = trim(strip_tags((string)($data['notes'] ?? '')));
                $validUntil = (string)($data['valid_until'] ?? '');
                if ($subject === '' || $introduction === '' || $scope === '' || $commercial === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) { $this->jsonMessage('Preencha assunto, apresentação, escopo, condições e validade.', 'warning'); return; }
                $response = new ProposalResponse();
                $response->proposal_id = $proposal->id; $response->created_by = $user->id; $response->template_type = $template;
                $response->subject = $subject; $response->introduction = $introduction; $response->scope = $scope; $response->commercial_terms = $commercial;
                $response->payment_terms = $payment ?: null; $response->valid_until = $validUntil; $response->notes = $notes ?: null; $response->status = 'draft';
                if (!$response->save()) { $this->jsonMessage('Não foi possível salvar a resposta.', 'error'); return; }
                try { $response->pdf_path = (new ProposalPdf())->generate($proposal, $response); } catch (\Throwable $exception) { \Source\Support\AppLogger::exception($exception, 'proposals', ['event_type'=>'proposal_pdf_failed','proposal_id'=>$proposal->id]); $this->jsonMessage('Não foi possível gerar o PDF.', 'error'); return; }
                $response->status = 'generated'; $response->save();
                if ($data['action'] === 'send_pdf') {
                    $absolute = dirname(__DIR__, 3) . '/storage/' . $response->pdf_path;
                    $queued = (new Email())->bootstrap($subject, ProposalMailer::response($proposal), $proposal->email, $proposal->name)
                        ->attach($absolute, 'proposta-' . strtolower($proposal->protocol) . '.pdf')
                        ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, null, (int)$response->id);
                    if (!$queued) { $response->status = 'failed'; $response->save(); $this->jsonMessage('O PDF foi gerado, mas o e-mail não entrou na fila.', 'warning'); return; }
                    $response->status = 'queued'; $response->queued_at = date('Y-m-d H:i:s'); $response->save();
                    $proposal->status = 'proposal_sent'; $proposal->save();
                }
                echo json_encode(['redirect' => url('/studio/proposals/' . $proposal->id)]); return;
            }
            $statuses = ['new','contacted','qualified','proposal_sent','won','lost','archived'];
            $next = (string)($data['status'] ?? '');
            if (!in_array($next, $statuses, true)) { $this->jsonMessage('Status inválido.', 'warning'); return; }
            $proposal->status = $next;
            $proposal->assigned_to = filter_var($data['assigned_to'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if ($next === 'contacted' && !$proposal->contacted_at) $proposal->contacted_at = date('Y-m-d H:i:s');
            if (!$proposal->save()) { $this->jsonMessage('Não foi possível atualizar a proposta.', 'error'); return; }
            echo json_encode(['redirect' => url('/studio/proposals/' . $proposal->id)]); return;
        }
        $team = Connect::getInstance()->query("SELECT DISTINCT u.id,u.first_name,u.last_name FROM users u JOIN access_user_roles ur ON ur.user_id=u.id JOIN access_roles r ON r.id=ur.role_id WHERE r.slug IN ('developer','super_admin','client_admin','manager') AND u.status<>'trash' ORDER BY u.first_name")->fetchAll() ?: [];
        $responses = (new ProposalResponse())->find('proposal_id=:proposal', 'proposal='.$proposal->id)->order('id DESC')->fetch(true) ?: [];
        echo $this->view->render('components/proposals/detail', $this->viewData('Proposta ' . $proposal->protocol, 'proposals', $user, ['proposal' => $proposal, 'team' => $team, 'responses' => $responses]));
    }

    public function proposalPdf(array $data): void
    {
        $this->guard('proposals.manage');
        $response = (new ProposalResponse())->findById((int)($data['id'] ?? 0));
        if (!$response || !$response->pdf_path) { redirect('/studio/proposals'); return; }
        $root = realpath(dirname(__DIR__, 3) . '/storage/files/proposals');
        $file = realpath(dirname(__DIR__, 3) . '/storage/' . $response->pdf_path);
        if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { redirect('/studio/ops/404'); return; }
        header('Content-Type: application/pdf'); header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="proposta-' . (int)$response->id . '.pdf"');
        readfile($file);
    }

    public function posts(?array $data): void
    {
        $user = $this->guard("articles.manage");
        $search = trim((string)($data["s"] ?? ""));
        if ($search !== "" && empty($data["page"])) {
            redirect("/studio/blog/home?search=" . urlencode($search));
        }
        $search = trim((string)($_GET["search"] ?? $search));
        $find = (new Post())->find($search ? "title LIKE :s" : null, $search ? "s=%{$search}%" : null);
        $pager = new Pager(url("/studio/blog/home/p/") . "page" . ($search ? "?search=" . urlencode($search) : ""));
        $pager->pager($find->count(), 20, (int)($data["page"] ?? 1));

        echo $this->view->render("components/blog/home", $this->viewData("Artigos", "blog", $user, [
            "posts" => $find->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true) ?: [],
            "search" => $search,
            "paginator" => $pager->render()
        ]));
    }

    public function post(?array $data): void
    {
        $user = $this->guard("articles.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $post = $id ? (new Post())->findById($id) : null;
        if ($id && !$post) {
            redirect("/studio/blog/home");
            return;
        }

        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "delete") {
                $target = (new Post())->findById((int)($data["post_id"] ?? 0));
                if (!$target) {
                    $this->jsonMessage("Artigo não encontrado.", "warning");
                    return;
                }
                $coverPath = $target->cover ? dirname(__DIR__, 3) . "/" . CONF_UPLOAD_DIR . "/" . $target->cover : null;
                if (!$target->destroy()) {
                    $this->jsonMessage("Não foi possível excluir o artigo.", "error");
                    return;
                }
                if ($coverPath) {
                    (new Upload())->remove($coverPath);
                }
                echo json_encode(["redirect" => url("/studio/blog/home")]);
                return;
            }

            $title = trim(strip_tags($data["title"] ?? ""));
            $subtitle = trim(strip_tags($data["subtitle"] ?? ""));
            $content = trim((string)($data["content"] ?? ""));
            $categoryId = filter_var($data["category"] ?? null, FILTER_VALIDATE_INT);
            $authorId = filter_var($data["author"] ?? null, FILTER_VALIDATE_INT);
            $status = in_array(($data["submit_status"] ?? $data["status"] ?? "draft"), ["post", "draft", "trash"], true) ? ($data["submit_status"] ?? $data["status"] ?? "draft") : "draft";
            $publishedAt = !empty($data["post_at"]) ? strtotime($data["post_at"]) : time();

            if (mb_strlen($title) < 5 || mb_strlen($title) > 140) {
                $this->jsonMessage("O título deve ter entre 5 e 140 caracteres.", "warning"); return;
            }
            if (mb_strlen($subtitle) < 10 || mb_strlen($subtitle) > 300) {
                $this->jsonMessage("O resumo deve ter entre 10 e 300 caracteres.", "warning"); return;
            }
            if (mb_strlen(trim(strip_tags($content))) < 50) {
                $this->jsonMessage("O conteúdo precisa ter pelo menos 50 caracteres.", "warning"); return;
            }
            if (!$categoryId || !(new Category())->findById($categoryId)) {
                $this->jsonMessage("Selecione uma categoria válida.", "warning"); return;
            }
            $author = $authorId ? (new User())->findById($authorId) : null;
            if (!$author || $author->level < self::ADMIN_LEVEL) {
                $this->jsonMessage("Selecione um autor autorizado.", "warning"); return;
            }
            if (!$publishedAt) {
                $this->jsonMessage("Informe uma data de publicação válida.", "warning"); return;
            }

            $video = trim(strip_tags($data["video"] ?? ""));
            if ($video && preg_match("~(?:youtu\\.be/|youtube(?:-nocookie)?\\.com/(?:watch\\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~", $video, $match)) {
                $video = $match[1];
            }
            if ($video && !preg_match("~^[A-Za-z0-9_-]{11}$~", $video)) {
                $this->jsonMessage("Informe uma URL ou ID válido do YouTube.", "warning"); return;
            }

            $isNewPost = !$post;
            $post = $post ?: new Post();
            $post->title = $title;
            $post->uri = str_slug(trim(strip_tags($data["uri"] ?? "")) ?: $title);
            $post->subtitle = $subtitle;
            $post->content = $content;
            $post->category = $categoryId;
            $post->author = $authorId;
            $post->status = $status;
            $post->post_at = date("Y-m-d H:i:s", $publishedAt);
            $post->video = $video ?: null;

            $oldCoverToRemove = null;
            $libraryCover = $this->safeMediaSelection((string)($data["library_cover"] ?? ""));
            if ($libraryCover) $post->cover = $libraryCover;
            if (!empty($_FILES["cover"]["tmp_name"])) {
                try {
                    $uploader = new Upload();
                    $cover = $uploader->image($_FILES["cover"], $post->uri . "-" . time(), 1920);
                    if (!$cover) {
                        echo json_encode(["message" => $uploader->message()->render()]); return;
                    }
                    $oldCoverToRemove = $post->cover;
                    $post->cover = $cover;
                } catch (\Throwable $exception) {
                    \Source\Support\AppLogger::exception($exception, 'upload', ['event_type' => 'article_cover_upload_failed']);
                    $this->jsonMessage("Não foi possível enviar a imagem de capa.", "error"); return;
                }
            }
            if ($status === "post" && empty($post->cover)) {
                $this->jsonMessage("Adicione uma imagem de capa antes de publicar.", "warning"); return;
            }
            if (!$post->save()) {
                echo json_encode(["message" => $post->message()->render()]);
                return;
            }
            if ($oldCoverToRemove) {
                (new Upload())->remove(dirname(__DIR__, 3) . "/" . CONF_UPLOAD_DIR . "/" . $oldCoverToRemove);
            }
            if ($isNewPost) {
                $articleMessage = new NotificationMessage();
                $articleMessage->sender_id = $user->id;
                $articleMessage->title = "Novo artigo: " . $post->title;
                $articleMessage->body = $post->subtitle;
                $articleMessage->audience = "all";
                $articleMessage->target_user_id = null;
                $articleMessage->severity = "info";
                $articleMessage->delivery_channels = "system";
                $articleMessage->link = $post->status === "post" ? url("/artigos/{$post->uri}") : url("/artigos");
                $articleMessage->status = "sent";
                $articleMessage->expires_at = date("Y-m-d H:i:s", strtotime("+1 month"));
                if ($articleMessage->save()) {
                    (new Communication())->deliver($articleMessage);
                    \Source\Support\Audit::record("create", "article_notification", (int)$articleMessage->id, [], ["post_id" => (int)$post->id, "audience" => "all"]);
                } else {
                    \Source\Support\AppLogger::log("warning", "Artigo criado sem concluir a notificação dos usuários", ["event_type" => "article_notification_failed", "post_id" => (int)$post->id], "articles");
                }
            }
            $this->message->success($status === "post" ? "Artigo publicado com sucesso." : "Rascunho salvo com sucesso.")->flash();
            echo json_encode(["redirect" => url("/studio/blog/post/{$post->id}")]);
            return;
        }

        echo $this->view->render("components/blog/post", $this->viewData($post ? "Editar artigo" : "Novo artigo", "blog", $user, [
            "post" => $post,
            "categories" => (new Category())->find()->order("title")->fetch(true) ?: [],
            "authors" => (new User())->find("level >= :l", "l=5")->order("first_name")->fetch(true) ?: [$user]
        ]));
    }

    public function postImage(?array $data): void
    {
        $this->guard("articles.manage");
        if (!csrf_verify($data ?? [])) {
            $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return;
        }
        if (empty($_FILES["image"]["tmp_name"])) {
            $this->jsonMessage("Selecione uma imagem para o conteúdo.", "warning"); return;
        }
        try {
            $uploader = new Upload();
            $uploaded = $uploader->image($_FILES["image"], "artigo-" . time(), 1600);
            if (!$uploaded) {
                echo json_encode(["message" => $uploader->message()->render()]); return;
            }
            echo json_encode(["mce_image" => '<img src="' . url("/" . CONF_UPLOAD_DIR . "/" . $uploaded) . '" alt="Imagem do artigo">']);
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, 'upload', ['event_type' => 'article_content_upload_failed']);
            $this->jsonMessage("Não foi possível enviar a imagem.", "error");
        }
    }

    public function notificationsCount(): void
    {
        $user = $this->guard("studio.access");
        $this->dispatchScheduledNotifications();
        $count = (new Notification())->find("users_id = :user AND view = 0 AND (expires_at IS NULL OR expires_at > NOW())", "user={$user->id}")->count();
        echo json_encode(["count" => $count]);
    }

    public function notificationsList(): void
    {
        $user = $this->guard("studio.access");
        $this->dispatchScheduledNotifications();
        $items = (new Notification())->find("users_id = :user AND (expires_at IS NULL OR expires_at > NOW())", "user={$user->id}")->order("view ASC, id DESC")->limit(8)->fetch(true) ?: [];
        $notifications = [];
        foreach ($items as $item) {
            $notifications[] = [
                "id" => (int)$item->id,
                "title" => htmlspecialchars($item->title, ENT_QUOTES, "UTF-8"),
                "body" => htmlspecialchars((string)$item->body, ENT_QUOTES, "UTF-8"),
                "severity" => $item->severity ?: "info",
                "action_url" => url("/studio/notifications/action/{$item->id}"),
                "content_url" => $this->safeNotificationLink((string)$item->link),
                "created_at" => date_fmt($item->created_at, "d/m/Y H:i"),
                "view" => (int)$item->view
            ];
        }
        echo json_encode(["notifications" => $notifications]);
    }

    public function notificationRead(array $data): void
    {
        $user = $this->guard();
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $notification = $id ? (new Notification())->find("id = :id AND users_id = :user", "id={$id}&user={$user->id}")->fetch() : null;
        if (!$notification) {
            redirect("/studio/dash");
            return;
        }
        $link = $this->safeNotificationLink((string)$notification->link) ?: url("/studio/dash");
        $notification->view = 1;
        $notification->read_at = date("Y-m-d H:i:s");
        $notification->save();
        redirect($link);
    }

    public function notificationAction(array $data): void
    {
        $user = $this->guard();
        if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $notification = $id ? (new Notification())->find("id = :id AND users_id = :user", "id={$id}&user={$user->id}")->fetch() : null;
        if (!$notification) { $this->jsonMessage("Notificação não encontrada.", "warning"); return; }
        $action = $data["action"] ?? "read";
        if ($action === "delete") {
            $notification->destroy();
            echo json_encode(["deleted" => true]);
            return;
        }
        if (!in_array($action, ["read", "open"], true)) { $this->jsonMessage("Ação inválida.", "warning"); return; }
        $notification->view = 1;
        $notification->read_at = date("Y-m-d H:i:s");
        $notification->save();
        $response = ["read" => true];
        if ($action === "open") { $response["redirect"] = $this->safeNotificationLink((string)$notification->link) ?: url("/studio/dash"); }
        echo json_encode($response);
    }

    public function notificationCenter(): void
    {
        $user = $this->guard("notifications.manage");
        $this->dispatchScheduledNotifications();
        $pdo = Connect::getInstance();
        $canAudit = $user->can("audit.view");
        $requestedTab = (string)($_GET["tab"] ?? "messages");
        $tab = in_array($requestedTab, ["messages", "queue", "audit"], true) ? $requestedTab : "messages";
        if ($tab === "audit" && !$canAudit) { $tab = "messages"; }
        $page = max(1, (int)($_GET["page"] ?? 1));
        $perPage = 15;
        $requestedPeriod = (int)($_GET["period"] ?? 30);
        $period = in_array($requestedPeriod, [1, 7, 15, 30, 60], true) ? $requestedPeriod : 30;
        $search = mb_substr(trim(strip_tags((string)($_GET["q"] ?? ""))), 0, 100);
        $dateFrom = $this->validFilterDate((string)($_GET["date_from"] ?? ""));
        $dateTo = $this->validFilterDate((string)($_GET["date_to"] ?? ""));
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

        $users = (new User())->find()->order("first_name, last_name")->fetch(true) ?: [];
        $userMap = [];
        foreach ($users as $item) { $userMap[$item->id] = $item->fullName(); }
        $messages = [];
        $mailQueue = [];
        $auditLogs = [];
        $total = 0;
        $statusFilter = "";
        $actionFilter = "";
        $severityFilter = "";
        if ($tab === "messages") {
            $messageFind = (new NotificationMessage())->find();
            $total = $messageFind->count();
            $pages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $pages);
            $messages = $messageFind->order("id DESC")->limit($perPage)->offset(($page - 1) * $perPage)->fetch(true) ?: [];
        } elseif ($tab === "queue") {
            $allowedStatuses = ["pending", "processing", "retry", "sent", "failed", "cancelled"];
            $statusFilter = in_array($_GET["status"] ?? "", $allowedStatuses, true) ? $_GET["status"] : "";
            $where = [];
            $params = [];
            if ($dateFrom || $dateTo) {
                if ($dateFrom) { $where[] = "created_at>=:date_from"; $params["date_from"] = $dateFrom . " 00:00:00"; }
                if ($dateTo) { $where[] = "created_at<DATE_ADD(:date_to,INTERVAL 1 DAY)"; $params["date_to"] = $dateTo . " 00:00:00"; }
            } else { $where[] = "created_at>=DATE_SUB(NOW(),INTERVAL {$period} DAY)"; }
            if ($statusFilter) { $where[] = "status=:status"; $params["status"] = $statusFilter; }
            if ($search !== "") { $where[] = "(recipient_name LIKE :q OR recipient_email LIKE :q OR subject LIKE :q)"; $params["q"] = "%{$search}%"; }
            $whereSql = implode(" AND ", $where);
            $count = $pdo->prepare("SELECT COUNT(*) FROM mail_queue WHERE {$whereSql}");
            $count->execute($params);
            $total = (int)$count->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $pages);
            $query = $pdo->prepare("SELECT * FROM mail_queue WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
            $query->execute($params);
            $mailQueue = $query->fetchAll() ?: [];
        } else {
            $allowedActions = ["create", "update", "delete", "login", "logout"];
            $allowedSeverities = ["info", "warning", "error"];
            $actionFilter = in_array($_GET["action"] ?? "", $allowedActions, true) ? $_GET["action"] : "";
            $severityFilter = in_array($_GET["severity"] ?? "", $allowedSeverities, true) ? $_GET["severity"] : "";
            $where = [];
            $params = [];
            if ($dateFrom || $dateTo) {
                if ($dateFrom) { $where[] = "created_at>=:date_from"; $params["date_from"] = $dateFrom . " 00:00:00"; }
                if ($dateTo) { $where[] = "created_at<DATE_ADD(:date_to,INTERVAL 1 DAY)"; $params["date_to"] = $dateTo . " 00:00:00"; }
            } else { $where[] = "created_at>=DATE_SUB(NOW(),INTERVAL {$period} DAY)"; }
            if ($actionFilter) { $where[] = "action=:action"; $params["action"] = $actionFilter; }
            if ($severityFilter) { $where[] = "severity=:severity"; $params["severity"] = $severityFilter; }
            if ($search !== "") { $where[] = "(description LIKE :q OR entity LIKE :q OR entity_id LIKE :q)"; $params["q"] = "%{$search}%"; }
            $whereSql = implode(" AND ", $where);
            $count = $pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE {$whereSql}");
            $count->execute($params);
            $total = (int)$count->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $pages);
            $query = $pdo->prepare("SELECT * FROM system_audit_logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
            $query->execute($params);
            $auditLogs = $query->fetchAll() ?: [];
        }
        $pages = $pages ?? 1;
        $tabCounts = [
            "messages" => (int)$pdo->query("SELECT COUNT(*) FROM notification_messages")->fetchColumn(),
            "queue" => (int)$pdo->query("SELECT COUNT(*) FROM mail_queue")->fetchColumn(),
            "audit" => $canAudit ? (int)$pdo->query("SELECT COUNT(*) FROM system_audit_logs")->fetchColumn() : 0
        ];
        echo $this->view->render("components/notifications/home", $this->viewData("Notificações", "notifications", $user, [
            "messages" => $messages,
            "auditLogs" => $auditLogs,
            "canAudit" => $canAudit,
            "users" => $users,
            "userMap" => $userMap,
            "unreadCount" => (new Notification())->find("users_id = :user AND view = 0", "user={$user->id}")->count(),
            "mailQueue" => $mailQueue,
            "tab" => $tab,
            "tabCounts" => $tabCounts,
            "page" => $page,
            "pages" => $pages,
            "total" => $total,
            "period" => $period,
            "dateFrom" => $dateFrom,
            "dateTo" => $dateTo,
            "search" => $search,
            "statusFilter" => $statusFilter,
            "actionFilter" => $actionFilter,
            "severityFilter" => $severityFilter
        ]));
    }

    private function validFilterDate(string $value): ?string
    {
        if ($value === "") return null;
        $date = \DateTimeImmutable::createFromFormat("!Y-m-d", $value);
        return $date && $date->format("Y-m-d") === $value ? $value : null;
    }

    public function notificationCompose(?array $data): void
    {
        $user = $this->guard("notifications.manage");
        if (!csrf_verify($data ?? [])) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
        $title = trim(strip_tags($data["title"] ?? ""));
        $body = trim(strip_tags($data["body"] ?? ""));
        $audience = in_array(($data["audience"] ?? "all"), ["all", "admins", "master", "user"], true) ? $data["audience"] : "all";
        $severity = in_array(($data["severity"] ?? "info"), ["info", "success", "warning", "error"], true) ? $data["severity"] : "info";
        $deliveryChannels = in_array(($data["delivery_channels"] ?? "system"), ["system", "email", "both"], true) ? $data["delivery_channels"] : "system";
        $target = $audience === "user" ? filter_var($data["target_user_id"] ?? null, FILTER_VALIDATE_INT) : null;
        $scheduled = !empty($data["scheduled_at"]) ? strtotime($data["scheduled_at"]) : false;
        $expires = !empty($data["expires_at"]) ? strtotime($data["expires_at"]) : false;
        if (mb_strlen($title) < 4 || mb_strlen($title) > 255 || mb_strlen($body) < 5) { $this->jsonMessage("Informe um título e uma mensagem válidos.", "warning"); return; }
        if ($audience === "user" && (!$target || !(new User())->findById($target))) { $this->jsonMessage("Selecione um usuário válido.", "warning"); return; }
        if ($expires && $expires <= time()) { $this->jsonMessage("A expiração deve ser uma data futura.", "warning"); return; }
        $message = new NotificationMessage();
        $message->sender_id = $user->id;
        $message->title = $title;
        $message->body = $body;
        $message->audience = $audience;
        $message->target_user_id = $target ?: null;
        $message->severity = $severity;
        $message->delivery_channels = $deliveryChannels;
        $messageLink = $this->safeNotificationLink(trim(strip_tags($data["link"] ?? "")));
        if ($messageLink === null) { $this->jsonMessage("Use somente um link interno deste sistema.", "warning"); return; }
        $message->link = $messageLink;
        $message->scheduled_at = $scheduled ? date("Y-m-d H:i:s", $scheduled) : null;
        $message->expires_at = $expires ? date("Y-m-d H:i:s", $expires) : null;
        $message->status = ($scheduled && $scheduled > time()) ? "scheduled" : "sent";
        if (!$message->save()) { echo json_encode(["message" => $message->message()->render()]); return; }
        if ($message->status === "sent") { $this->deliverNotificationMessage($message); }
        $channelLabel = $deliveryChannels === "both" ? "notificação e e-mail" : ($deliveryChannels === "email" ? "e-mail" : "notificação");
        $this->message->success($message->status === "scheduled" ? ucfirst($channelLabel) . " agendado(a)." : ucfirst($channelLabel) . " preparado(a) para envio.")->flash();
        echo json_encode(["redirect" => url("/studio/notifications")]);
    }

    public function mailQueueAction(?array $data): void
    {
        $user = $this->guard("notifications.manage");
        if (!csrf_verify($data ?? [])) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
        $id = filter_var($data["queue_id"] ?? null, FILTER_VALIDATE_INT);
        $action = $data["action"] ?? "";
        if (!$id || !in_array($action, ["retry", "cancel"], true)) { $this->jsonMessage("Ação inválida.", "warning"); return; }
        $pdo = Connect::getInstance();
        $item = $pdo->prepare("SELECT * FROM mail_queue WHERE id = :id LIMIT 1");
        $item->execute(["id" => $id]);
        $queue = $item->fetch();
        if (!$queue) { $this->jsonMessage("E-mail não encontrado na fila.", "warning"); return; }
        if ($action === "retry") {
            if ($queue->status === "sent") { $this->jsonMessage("Este e-mail já foi enviado.", "warning"); return; }
            $stmt = $pdo->prepare("UPDATE mail_queue SET status = 'pending', attempts = 0, scheduled_at = NOW(), next_attempt_at = NULL, failed_at = NULL, cancelled_at = NULL, error_message = NULL WHERE id = :id");
            $message = "E-mail devolvido à fila para nova tentativa.";
        } else {
            if ($queue->status === "sent") { $this->jsonMessage("Um e-mail já enviado não pode ser cancelado.", "warning"); return; }
            $stmt = $pdo->prepare("UPDATE mail_queue SET status = 'cancelled', cancelled_at = NOW(), next_attempt_at = NULL WHERE id = :id");
            $message = "Envio cancelado.";
        }
        $stmt->execute(["id" => $id]);
        \Source\Support\Audit::record("update", "mail_queue", $id, ["status" => $queue->status], ["status" => $action === "retry" ? "pending" : "cancelled", "actor" => $user->id]);
        $this->message->success($message)->flash();
        echo json_encode(["reload" => true]);
    }

    public function notificationsMarkAll(?array $data): void
    {
        $user = $this->guard();
        if (!csrf_verify($data ?? [])) { $this->jsonMessage("Sessão expirada.", "error"); return; }
        $items = (new Notification())->find("users_id = :user AND view = 0", "user={$user->id}")->fetch(true) ?: [];
        foreach ($items as $item) { $item->view = 1; $item->read_at = date("Y-m-d H:i:s"); $item->save(); }
        echo json_encode(["reload" => true]);
    }

    public function categories(): void
    {
        $user = $this->guard("articles.manage");
        echo $this->view->render("components/blog/categories", $this->viewData("Categorias", "blog", $user, [
            "categories" => (new Category())->find()->order("title")->fetch(true) ?: []
        ]));
    }

    public function category(?array $data): void
    {
        $user = $this->guard("articles.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $category = $id ? (new Category())->findById($id) : null;

        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "delete") {
                $target = (new Category())->findById((int)($data["category_id"] ?? 0));
                if (!$target || $target->posts()->count()) {
                    $this->jsonMessage("A categoria possui artigos ou não foi encontrada.", "warning");
                    return;
                }
                $target->destroy();
                echo json_encode(["reload" => true]);
                return;
            }
            $category = $category ?: new Category();
            $category->title = trim(strip_tags($data["title"] ?? ""));
            $category->uri = str_slug($category->title);
            $category->description = trim(strip_tags($data["description"] ?? ""));
            if (!$category->save()) {
                echo json_encode(["message" => $category->message()->render()]);
                return;
            }
            echo json_encode(["redirect" => url("/studio/blog/categories")]);
            return;
        }

        echo $this->view->render("components/blog/category", $this->viewData($category ? "Editar categoria" : "Nova categoria", "blog", $user, ["category" => $category]));
    }

    public function pages(): void
    {
        $user = $this->guard("pages.manage");
        echo $this->view->render("components/pages/home", $this->viewData("Páginas", "pages", $user, [
            "pages" => (new Page())->find()->order("id DESC")->fetch(true) ?: []
        ]));
    }

    public function page(?array $data): void
    {
        $user = $this->guard("pages.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $page = $id ? (new Page())->findById($id) : null;
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "delete") {
                $target = (new Page())->findById((int)($data["page_id"] ?? 0));
                if (!$target) {
                    $this->jsonMessage("Página não encontrada.", "warning");
                    return;
                }
                $target->destroy();
                echo json_encode(["redirect" => url("/studio/pages")]);
                return;
            }
            $page = $page ?: new Page();
            $page->title = trim(strip_tags($data["title"] ?? ""));
            $page->uri = str_slug(trim(strip_tags($data["uri"] ?? $page->title)));
            $page->content = $data["content"] ?? "";
            $page->author = $user->id;
            $page->status = in_array(($data["status"] ?? "draft"), ["post", "draft", "trash"], true) ? $data["status"] : "draft";
            $page->post_at = !empty($data["post_at"]) ? date("Y-m-d H:i:s", strtotime($data["post_at"])) : date("Y-m-d H:i:s");
            $libraryCover = $this->safeMediaSelection((string)($data["library_cover"] ?? ""));
            if ($libraryCover) $page->cover = $libraryCover;
            if (!empty($_FILES["cover"]["tmp_name"])) {
                $cover = (new Upload())->image($_FILES["cover"], $page->uri, 1920);
                if ($cover) $page->cover = $cover;
            }
            if (!$page->save()) {
                echo json_encode(["message" => $page->message()->render()]);
                return;
            }
            echo json_encode(["redirect" => url("/studio/page/{$page->id}")]);
            return;
        }
        echo $this->view->render("components/pages/form", $this->viewData($page ? "Editar página" : "Nova página", "pages", $user, ["page" => $page]));
    }

    public function media(?array $data): void
    {
        $user = $this->guard("media.manage");
        $root = dirname(__DIR__, 3);
        $base = $root . "/" . CONF_UPLOAD_DIR . "/" . CONF_UPLOAD_IMAGE_DIR;

        if (($data["action"] ?? null) === "upload") {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if (empty($_FILES["image"]["tmp_name"])) { $this->jsonMessage("Selecione uma imagem para enviar.", "warning"); return; }
            $uploaded = (new Upload())->image($_FILES["image"], "midia-" . time(), 2000);
            if (!$uploaded) { $this->jsonMessage("Não foi possível enviar a imagem.", "error"); return; }
            \Source\Support\Audit::record("create", "media_file", null, [], ["path" => $uploaded]);
            echo json_encode(["reload" => true]);
            return;
        }

        if (($data["action"] ?? null) === "delete_selected") {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            $selected = array_values(array_unique(array_filter((array)($data["paths"] ?? []), "is_string")));
            if (!$selected) { $this->jsonMessage("Selecione pelo menos uma imagem.", "warning"); return; }
            $deleted = 0;
            $protected = 0;
            $invalid = 0;
            $baseReal = realpath($base);
            foreach (array_slice($selected, 0, 100) as $selectedPath) {
                $relativePath = ltrim(str_replace(["\\", "\0"], ["/", ""], $selectedPath), "/");
                $candidate = realpath($root . "/" . $relativePath);
                if (!$candidate || !$baseReal || !is_file($candidate) || !str_starts_with($candidate, $baseReal . DIRECTORY_SEPARATOR)) { $invalid++; continue; }
                $storedPath = preg_replace("~^" . preg_quote(CONF_UPLOAD_DIR . "/", "~") . "~", "", $relativePath);
                if ($this->mediaUsage($storedPath)["count"] > 0) { $protected++; continue; }
                if (@unlink($candidate)) {
                    $deleted++;
                    \Source\Support\Audit::record("delete", "media_file", null, ["path" => $relativePath, "name" => basename($candidate)], ["bulk" => true]);
                } else { $invalid++; }
            }
            $message = "{$deleted} imagem(ns) removida(s).";
            if ($protected) $message .= " {$protected} mantida(s) porque estão em uso.";
            if ($invalid) $message .= " {$invalid} não puderam ser processada(s).";
            ($deleted ? $this->message->success($message) : $this->message->warning($message))->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        if (($data["action"] ?? null) === "delete") {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página e tente novamente.", "error");
                return;
            }
            $relativePath = ltrim(str_replace(["\\", "\0"], ["/", ""], (string)($data["path"] ?? "")), "/");
            $candidate = realpath($root . "/" . $relativePath);
            $baseReal = realpath($base);
            if (!$candidate || !$baseReal || !is_file($candidate) || !str_starts_with($candidate, $baseReal . DIRECTORY_SEPARATOR)) {
                $this->jsonMessage("Arquivo de mídia inválido ou não encontrado.", "warning");
                return;
            }
            $storedPath = preg_replace("~^" . preg_quote(CONF_UPLOAD_DIR . "/", "~") . "~", "", $relativePath);
            $usage = $this->mediaUsage($storedPath);
            if ($usage["count"] > 0) {
                $this->jsonMessage("A imagem está em uso por {$usage["count"]} registro(s): " . implode(", ", $usage["labels"]) . ". Remova os vínculos antes de excluí-la.", "warning");
                return;
            }
            if (!@unlink($candidate)) {
                $this->jsonMessage("Não foi possível excluir o arquivo. Verifique as permissões da pasta.", "error");
                return;
            }
            \Source\Support\Audit::record("delete", "media_file", null, ["path" => $relativePath, "name" => basename($candidate)], []);
            $this->message->success("Imagem excluída com segurança.")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        $search = mb_substr(trim(strip_tags((string)($_GET["q"] ?? ""))), 0, 100);
        $type = in_array($_GET["type"] ?? "", ["jpg", "png", "webp", "gif"], true) ? $_GET["type"] : "";
        $orientation = in_array($_GET["orientation"] ?? "", ["landscape", "portrait", "square"], true) ? $_GET["orientation"] : "";
        $usageFilter = in_array($_GET["usage"] ?? "", ["used", "free"], true) ? $_GET["usage"] : "";
        $sortInput = (string)($_GET["sort"] ?? "newest");
        $sort = in_array($sortInput, ["newest", "oldest", "name", "largest"], true) ? $sortInput : "newest";
        $periodInput = (string)($_GET["period"] ?? "all");
        $period = in_array($periodInput, ["today", "7", "30", "90", "all"], true) ? $periodInput : "all";
        $dateFrom = $this->validFilterDate((string)($_GET["date_from"] ?? ""));
        $dateTo = $this->validFilterDate((string)($_GET["date_to"] ?? ""));
        if (!$dateFrom && !$dateTo && $period !== "all") {
            $dateTo = date("Y-m-d");
            $dateFrom = $period === "today" ? $dateTo : date("Y-m-d", strtotime("-" . ((int)$period - 1) . " days"));
        }
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }
        $files = [];
        $privateUserPhotos = $this->privateUserMediaPaths();
        if (is_dir($base)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || str_contains($file->getPathname(), "/cache/")) continue;
                if (!in_array(strtolower($file->getExtension()), ["jpg", "jpeg", "png", "webp", "gif"], true)) continue;
                $path = str_replace($root . "/", "", $file->getPathname());
                $storedPath = preg_replace("~^" . preg_quote(CONF_UPLOAD_DIR . "/", "~") . "~", "", $path);
                if (isset($privateUserPhotos[$storedPath])) continue;
                $imageInfo = @getimagesize($file->getPathname());
                $candidate = [
                    "path" => $path, "name" => $file->getFilename(), "time" => $file->getMTime(), "size" => $file->getSize(),
                    "mime" => $imageInfo["mime"] ?? (function_exists("mime_content_type") ? @mime_content_type($file->getPathname()) : "application/octet-stream"),
                    "width" => $imageInfo[0] ?? null, "height" => $imageInfo[1] ?? null,
                    "extension" => strtoupper($file->getExtension()), "stored_path" => $storedPath
                ];
                $normalizedType = strtolower($file->getExtension()) === "jpeg" ? "jpg" : strtolower($file->getExtension());
                $imageOrientation = ($candidate["width"] ?? 0) === ($candidate["height"] ?? 0) ? "square" : (($candidate["width"] ?? 0) > ($candidate["height"] ?? 0) ? "landscape" : "portrait");
                if ($search !== "" && !str_contains(mb_strtolower($candidate["name"]), mb_strtolower($search))) continue;
                if ($type !== "" && $normalizedType !== $type) continue;
                if ($orientation !== "" && $imageOrientation !== $orientation) continue;
                if ($dateFrom && $candidate["time"] < strtotime($dateFrom . " 00:00:00")) continue;
                if ($dateTo && $candidate["time"] >= strtotime($dateTo . " +1 day")) continue;
                if ($usageFilter !== "") {
                    $candidateUsage = $this->mediaUsage($storedPath);
                    $candidate["usage"] = $candidateUsage["count"];
                    $candidate["usage_labels"] = $candidateUsage["labels"];
                    if ($usageFilter === "used" && $candidate["usage"] === 0) continue;
                    if ($usageFilter === "free" && $candidate["usage"] > 0) continue;
                }
                $files[] = $candidate;
            }
            usort($files, match ($sort) {
                "oldest" => fn($a, $b) => $a["time"] <=> $b["time"],
                "name" => fn($a, $b) => strcasecmp($a["name"], $b["name"]),
                "largest" => fn($a, $b) => $b["size"] <=> $a["size"],
                default => fn($a, $b) => $b["time"] <=> $a["time"]
            });
        }
        $total = count($files);
        $page = max(1, (int)($data["page"] ?? 1));
        $filterQuery = http_build_query(array_filter(["q" => $search, "type" => $type, "orientation" => $orientation, "usage" => $usageFilter, "sort" => $sort !== "newest" ? $sort : "", "period" => $period !== "all" ? $period : "", "date_from" => !empty($_GET["date_from"]) ? $dateFrom : "", "date_to" => !empty($_GET["date_to"]) ? $dateTo : ""], static fn($value) => $value !== "" && $value !== null));
        $pager = new Pager(url("/studio/media/p/") . "page" . ($filterQuery ? "?{$filterQuery}" : ""), "Página", ["Página anterior", "‹"], ["Próxima página", "›"]);
        $pager->pager($total, 25, $page, 2);
        $visibleFiles = array_slice($files, $pager->offset(), $pager->limit());
        foreach ($visibleFiles as &$visibleFile) {
            if (!isset($visibleFile["usage"])) {
                $usage = $this->mediaUsage($visibleFile["stored_path"]);
                $visibleFile["usage"] = $usage["count"];
                $visibleFile["usage_labels"] = $usage["labels"];
            }
            unset($visibleFile["stored_path"]);
        }
        unset($visibleFile);
        echo $this->view->render("components/media/home", $this->viewData("Biblioteca de mídia", "media", $user, [
            "files" => $visibleFiles, "total" => $total,
            "page" => $pager->page(), "pages" => $pager->pages(), "paginator" => $pager->render("studio-pagination", false),
            "search" => $search, "type" => $type, "orientation" => $orientation, "usageFilter" => $usageFilter, "sort" => $sort,
            "period" => $period, "dateFrom" => $dateFrom, "dateTo" => $dateTo, "filterQuery" => $filterQuery
        ]));
    }

    public function mediaLibrary(): void
    {
        $this->guard("media.manage");
        $root = dirname(__DIR__, 3);
        $base = $root . "/" . CONF_UPLOAD_DIR . "/" . CONF_UPLOAD_IMAGE_DIR;
        $files = [];
        $privateUserPhotos = $this->privateUserMediaPaths();
        if (is_dir($base)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || str_contains($file->getPathname(), "/cache/") || !in_array(strtolower($file->getExtension()), ["jpg","jpeg","png","webp","gif"], true)) continue;
                $relative = str_replace($root . "/", "", $file->getPathname());
                $stored = preg_replace("~^" . preg_quote(CONF_UPLOAD_DIR . "/", "~") . "~", "", $relative);
                if (isset($privateUserPhotos[$stored])) continue;
                $info = @getimagesize($file->getPathname());
                $files[] = ["name" => $file->getFilename(), "path" => $stored, "url" => url("/" . $relative), "width" => $info[0] ?? 0, "height" => $info[1] ?? 0, "time" => $file->getMTime()];
            }
            usort($files, fn($a, $b) => $b["time"] <=> $a["time"]);
        }
        echo json_encode(["images" => array_slice($files, 0, 120)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function editorImage(?array $data): void
    {
        $this->guard("media.manage");
        if (!csrf_verify($data ?? [])) {
            http_response_code(419);
            echo json_encode(["error" => "Sessão expirada. Atualize a página."]);
            return;
        }
        if (empty($_FILES["image"]["tmp_name"])) {
            http_response_code(422);
            echo json_encode(["error" => "Selecione uma imagem válida."]);
            return;
        }
        $upload = new Upload();
        $stored = $upload->image($_FILES["image"], "editor-" . date("YmdHis") . "-" . bin2hex(random_bytes(3)), 2000);
        if (!$stored) {
            http_response_code(422);
            echo json_encode(["error" => "Não foi possível enviar a imagem."]);
            return;
        }
        \Source\Support\Audit::record("create", "media_file", null, [], ["path" => $stored, "source" => "organic_editor"]);
        echo json_encode([
            "url" => url("/" . CONF_UPLOAD_DIR . "/" . $stored),
            "path" => $stored,
            "name" => basename($stored),
            "type" => mime_content_type(dirname(__DIR__, 3) . "/" . CONF_UPLOAD_DIR . "/" . $stored) ?: "image/jpeg"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function mediaUsage(string $storedPath): array
    {
        $locations = [
            "posts" => ["cover", "artigos"], "pages" => ["cover", "páginas"], "slides" => ["cover", "destaques"],
            "categories" => ["cover", "categorias"], "categories_slide" => ["cover", "categorias de destaque"],
            "users" => ["photo", "usuários"], "app_condominium" => ["photo", "condomínios"], "app_corporations" => ["photo", "empresas"],
            "notifications" => ["image", "notificações"], "notifications_categories" => ["cover", "categorias de notificação"],
            "settings" => ["site_photo", "configurações"], "support_articles" => ["cover", "matérias de suporte"]
        ];
        $count = 0;
        $labels = [];
        try {
            $pdo = Connect::getInstance();
            foreach ($locations as $table => [$column, $label]) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :path");
                $stmt->execute(["path" => $storedPath]);
                $matches = (int)$stmt->fetchColumn();
                if ($matches > 0) {
                    $count += $matches;
                    $labels[] = "{$matches} em {$label}";
                }
            }
            foreach (["posts" => "conteúdos de artigos", "pages" => "conteúdos de páginas", "support_articles" => "conteúdos de suporte"] as $table => $label) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `content` LIKE :path");
                $stmt->execute(["path" => "%{$storedPath}%"]);
                $matches = (int)$stmt->fetchColumn();
                if ($matches > 0) {
                    $count += $matches;
                    $labels[] = "{$matches} em {$label}";
                }
            }
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, 'media', ['event_type' => 'media_usage_check_failed', 'stored_path' => $storedPath]);
            error_log("Media usage error: " . $exception->getMessage());
        }
        return ["count" => $count, "labels" => $labels];
    }

    private function privateUserMediaPaths(): array
    {
        try {
            $paths = Connect::getInstance()->query("SELECT photo FROM users WHERE photo IS NOT NULL AND photo<>''")->fetchAll(\PDO::FETCH_COLUMN);
            return array_fill_keys(array_map(static fn($path) => ltrim((string)$path, "/"), $paths), true);
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, "media", ["event_type" => "private_media_lookup_failed"]);
            return [];
        }
    }

    public function users(?array $data): void
    {
        $user = $this->guard("users.manage");
        $search = mb_substr(trim(strip_tags((string)($_GET["q"] ?? ""))), 0, 100);
        $status = in_array($_GET["status"] ?? "", ["confirmed", "registered"], true) ? $_GET["status"] : "";
        $terms = ["status<>'trash'"];
        $params = [];
        if ($search !== "") {
            $terms[] = "(first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3 OR document LIKE :q4)";
            $like = urlencode("%{$search}%");
            $params[] = "q1={$like}&q2={$like}&q3={$like}&q4={$like}";
        }
        if ($status !== "") {
            $terms[] = "status=:status";
            $params[] = "status={$status}";
        }
        $termsSql = implode(" AND ", $terms);
        $paramsSql = $params ? implode("&", $params) : null;
        if (($_GET["export"] ?? "") === "csv") {
            $exportUsers = (new User())->find($termsSql, $paramsSql)->order("first_name,last_name")->fetch(true) ?: [];
            header("Content-Type: text/csv; charset=UTF-8");
            header('Content-Disposition: attachment; filename="usuarios-movesos-' . date('Y-m-d') . '.csv"');
            $output = fopen("php://output", "wb");
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ["Nome", "E-mail", "Telefone", "Perfil", "Status", "Cadastro"], ";");
            foreach ($exportUsers as $exportUser) {
                $exportRole = $exportUser->accessRole();
                fputcsv($output, [$exportUser->fullName(), $exportUser->email, $exportUser->phone_cell, $exportRole->name ?? "Não definido", $exportUser->status === "confirmed" ? "Ativo" : "Inativo", date_fmt($exportUser->created_at, "d/m/Y")], ";");
            }
            fclose($output);
            return;
        }
        $find = (new User())->find($termsSql, $paramsSql);
        $queryString = http_build_query(array_filter(["q" => $search, "status" => $status], static fn($value) => $value !== ""));
        $pager = new Pager(url($this->environmentBase() . "/users/p/") . "page" . ($queryString ? "?{$queryString}" : ""), "Página", ["Anterior", "‹"], ["Próxima", "›"]);
        $pager->pager($find->count(), 12, max(1, (int)($data["page"] ?? 1)));
        $pdo = Connect::getInstance();
        $stats = $pdo->query("SELECT COUNT(*) total,SUM(status='confirmed') active,SUM(status<>'confirmed') inactive FROM users WHERE status<>'trash'")->fetch();
        $stats->admins = (int)$pdo->query("SELECT COUNT(*) FROM access_user_roles ur INNER JOIN access_roles r ON r.id=ur.role_id WHERE r.level>=50")->fetchColumn();
        $listedUsers = $find->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true) ?: [];
        $activityMap = [];
        $sessionRows = $pdo->query("SELECT users_id,MAX(COALESCE(updated_at,created_at)) last_access,COUNT(*) sessions FROM app_session GROUP BY users_id")->fetchAll();
        foreach ($sessionRows as $sessionRow) { $activityMap[(int)$sessionRow->users_id] = $sessionRow; }
        echo $this->view->render("components/users/studio", $this->viewData("Usuários", "users", $user, [
            "users" => $listedUsers,
            "stats" => $stats,
            "activityMap" => $activityMap,
            "search" => $search,
            "statusFilter" => $status,
            "paginator" => $pager->render(),
            "page" => max(1, (int)($data["page"] ?? 1)),
            "shown" => min($pager->limit(), max(0, $find->count() - $pager->offset())),
            "totalFiltered" => $find->count()
        ]));
    }

    public function userForm(?array $data): void
    {
        $user = $this->guard("users.manage");
        $pdo = Connect::getInstance();
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $targetUser = $id ? (new User())->findById($id) : null;
        $actorRole = AccessControl::role($user);
        if ($actorRole && $actorRole->slug === "developer") {
            $roles = $pdo->query("SELECT * FROM access_roles ORDER BY level DESC")->fetchAll();
        } else {
            $roleList = $pdo->prepare("SELECT * FROM access_roles WHERE level <= :level AND slug <> 'developer' ORDER BY level DESC");
            $roleList->execute(["level" => (int)($actorRole->level ?? 0)]);
            $roles = $roleList->fetchAll();
        }
        $permissions = $pdo->query("SELECT * FROM access_permissions ORDER BY group_name,name")->fetchAll();
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            $currentRole = $actorRole;
            $targetRole = $targetUser ? AccessControl::role($targetUser) : null;
            if ($targetRole && (!$currentRole || ($currentRole->slug !== "developer" && (int)$targetRole->level > (int)$currentRole->level))) { $this->jsonMessage("Você não pode alterar um usuário de perfil superior.", "error"); return; }

            if ($data["action"] === "profile") {
                if (!$targetUser) { $this->jsonMessage("Usuário não encontrado.", "warning"); return; }
                $before = ["first_name" => $targetUser->first_name, "last_name" => $targetUser->last_name, "email" => $targetUser->email, "phone_cell" => $targetUser->phone_cell, "status" => $targetUser->status];
                $targetUser->first_name = trim(strip_tags($data["first_name"] ?? ""));
                $targetUser->last_name = trim(strip_tags($data["last_name"] ?? ""));
                $targetUser->email = trim(strip_tags($data["email"] ?? ""));
                $targetUser->document = preg_replace("/\D/", "", $data["document"] ?? "");
                $targetUser->phone_cell = preg_replace("/\D/", "", $data["phone_cell"] ?? "");
                $targetUser->genre = in_array($data["genre"] ?? "", ["uninformed", "male", "female", "other"], true) ? $data["genre"] : "uninformed";
                $targetUser->datebirth = !empty($data["datebirth"]) ? $data["datebirth"] : null;
                $targetUser->status = in_array($data["status"] ?? "", ["confirmed", "registered"], true) ? $data["status"] : "registered";
                if (!empty($data["remove_photo"])) $targetUser->photo = "";
                if (!empty($_FILES["photo"]["tmp_name"])) {
                    if ((int)($_FILES["photo"]["size"] ?? 0) > 3145728) { $this->jsonMessage("A foto deve ter no máximo 3 MB.", "warning"); return; }
                    $upload = new Upload();
                    $photo = $upload->image($_FILES["photo"], "profile-{$targetUser->id}-" . time(), 600);
                    if (!$photo) { echo json_encode(["message" => $upload->message()->render()]); return; }
                    $targetUser->photo = $photo;
                }
                if (!$targetUser->save()) { echo json_encode(["message" => $targetUser->message()->render()]); return; }
                \Source\Support\Audit::record("update", "users", (int)$targetUser->id, $before, ["first_name" => $targetUser->first_name, "last_name" => $targetUser->last_name, "email" => $targetUser->email, "phone_cell" => $targetUser->phone_cell, "status" => $targetUser->status]);
                echo json_encode(["redirect" => url($this->environmentBase() . "/user/{$targetUser->id}#dados")]);
                return;
            }

            if ($data["action"] === "address") {
                if (!$targetUser) { $this->jsonMessage("Usuário não encontrado.", "warning"); return; }
                $address = $targetUser->address("main") ?: new Address();
                $address->users_id = (int)$targetUser->id;
                $address->description = mb_substr(trim(strip_tags((string)($data["description"] ?? "Endereço principal"))), 0, 255);
                $address->code = preg_replace("/\D/", "", (string)($data["code"] ?? ""));
                $address->city = mb_substr(trim(strip_tags((string)($data["city"] ?? ""))), 0, 255);
                $address->state = strtoupper(mb_substr(trim(strip_tags((string)($data["state"] ?? ""))), 0, 2));
                $address->district = mb_substr(trim(strip_tags((string)($data["district"] ?? ""))), 0, 255);
                $address->street = mb_substr(trim(strip_tags((string)($data["street"] ?? ""))), 0, 255);
                $address->number = mb_substr(trim(strip_tags((string)($data["number"] ?? ""))), 0, 255);
                $address->complement = mb_substr(trim(strip_tags((string)($data["complement"] ?? ""))), 0, 255);
                $address->status = "main";
                if (strlen($address->code) !== 8 || !preg_match("/^[A-Z]{2}$/", $address->state)) { $this->jsonMessage("Informe um CEP e uma UF válidos.", "warning"); return; }
                if (!$address->save()) { echo json_encode(["message" => $address->message()->render()]); return; }
                echo json_encode(["redirect" => url($this->environmentBase() . "/user/{$targetUser->id}#endereco")]);
                return;
            }

            $roleId = filter_var($data["role_id"] ?? null, FILTER_VALIDATE_INT);
            $roleStmt = $pdo->prepare("SELECT * FROM access_roles WHERE id=:id LIMIT 1");
            $roleStmt->execute(["id" => $roleId]);
            $role = $roleStmt->fetch();
            if (!$role) { $this->jsonMessage("Selecione um perfil válido.", "warning"); return; }
            if ($role->slug === "developer" && (!$currentRole || $currentRole->slug !== "developer")) { $this->jsonMessage("Somente um desenvolvedor pode atribuir esse perfil.", "error"); return; }
            if (!$currentRole || ($currentRole->slug !== "developer" && (int)$role->level > (int)$currentRole->level)) { $this->jsonMessage("Você não pode atribuir um perfil superior ao seu.", "error"); return; }
            if ($targetUser && (int)$targetUser->id === (int)$user->id && $targetRole && $targetRole->slug === "developer" && $role->slug !== "developer") {
                $developers = $pdo->query("SELECT COUNT(*) FROM access_user_roles ur JOIN access_roles r ON r.id=ur.role_id WHERE r.slug='developer'")->fetchColumn();
                if ((int)$developers <= 1) { $this->jsonMessage("Cadastre outro desenvolvedor antes de retirar seu próprio acesso técnico.", "warning"); return; }
            }
            foreach ((array)($data["permissions"] ?? []) as $permissionSlug => $effect) {
                if ($effect === "allow" && $currentRole->slug !== "developer" && !$user->can($permissionSlug)) { $this->jsonMessage("Você não pode conceder a permissão {$permissionSlug}, pois não possui esse acesso.", "error"); return; }
            }
            if ($data["action"] === "access") {
                if (!$targetUser) { $this->jsonMessage("Usuário não encontrado.", "warning"); return; }
                $this->syncUserAccess((int)$targetUser->id, (int)$role->id, (array)($data["permissions"] ?? []), (int)$user->id);
                echo json_encode(["redirect" => url($this->environmentBase() . "/user/{$targetUser->id}")]);
                return;
            }
            $newUser = (new User())->bootstrap(
                trim(strip_tags($data["first_name"] ?? "")),
                trim(strip_tags($data["last_name"] ?? "")),
                trim(strip_tags($data["email"] ?? "")),
                preg_replace("/\D/", "", $data["document"] ?? ""),
                $data["password"] ?? ""
            );
            $newUser->level = (int)$role->level;
            $newUser->status = "registered";
            $newUser->privacy = "reject";
            if (!$newUser->save()) {
                echo json_encode(["message" => $newUser->message()->render()]);
                return;
            }
            $this->syncUserAccess((int)$newUser->id, (int)$role->id, (array)($data["permissions"] ?? []), (int)$user->id);
            echo json_encode(["redirect" => url($this->environmentBase() . "/user/{$newUser->id}")]);
            return;
        }
        $overrides = [];
        if ($targetUser) {
            $stmt = $pdo->prepare("SELECT p.slug,o.effect FROM access_user_overrides o JOIN access_permissions p ON p.id=o.permission_id WHERE o.user_id=:user");
            $stmt->execute(["user" => $targetUser->id]);
            foreach ($stmt->fetchAll() as $override) { $overrides[$override->slug] = $override->effect; }
        }
        $defaultRole = null;
        if (!$targetUser) { foreach ($roles as $availableRole) { if ($availableRole->slug === "user") { $defaultRole = $availableRole; break; } } }
        echo $this->view->render("components/users/form", $this->viewData($targetUser ? "Acessos do usuário" : "Novo usuário", "users", $user, ["targetUser" => $targetUser, "address" => $targetUser ? $targetUser->address("main") : null, "roles" => $roles, "permissions" => $permissions, "overrides" => $overrides, "selectedRole" => $targetUser ? AccessControl::role($targetUser) : $defaultRole]));
    }

    public function testimonials(?array $data): void
    {
        $user = $this->guard("testimonials.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $testimonial = $id ? (new AppBrief())->findById((int)$id) : null;
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if ($data["action"] === "delete") {
                if (!$testimonial) { $this->jsonMessage("Depoimento não encontrado.", "warning"); return; }
                $testimonial->destroy();
                echo json_encode(["redirect" => url("/studio/testimonials")]);
                return;
            }
            $testimonial = $testimonial ?: new AppBrief();
            $testimonial->title = mb_substr(trim(strip_tags((string)($data["title"] ?? ""))), 0, 180);
            $testimonial->townhouse = mb_substr(trim(strip_tags((string)($data["townhouse"] ?? ""))), 0, 180);
            $testimonial->content = trim(strip_tags((string)($data["content"] ?? "")));
            $testimonial->rating = min(5, max(1, (int)($data["rating"] ?? 5)));
            $testimonial->status = ($data["status"] ?? "draft") === "published" ? "published" : "draft";
            $libraryCover = $this->safeMediaSelection((string)($data["library_cover"] ?? ""));
            if ($libraryCover) $testimonial->cover = $libraryCover;
            if (!empty($_FILES["cover"]["tmp_name"])) {
                $upload = new Upload();
                $cover = $upload->image($_FILES["cover"], "depoimento-" . str_slug($testimonial->title) . "-" . time(), 700);
                if (!$cover) { echo json_encode(["message" => $upload->message()->render()]); return; }
                $testimonial->cover = $cover;
            }
            if (mb_strlen($testimonial->title) < 3 || mb_strlen($testimonial->content) < 10) { $this->jsonMessage("Informe o nome e um depoimento com pelo menos 10 caracteres.", "warning"); return; }
            if (!$testimonial->save()) { echo json_encode(["message" => $testimonial->message()->render()]); return; }
            echo json_encode(["redirect" => url("/studio/testimonials/{$testimonial->id}")]);
            return;
        }
        echo $this->view->render("components/testimonials/home", $this->viewData("Depoimentos", "testimonials", $user, [
            "testimonial" => $testimonial,
            "testimonials" => (new AppBrief())->find()->order("id DESC")->fetch(true) ?: []
        ]));
    }

    public function faqs(?array $data): void
    {
        $user = $this->guard("faqs.manage");
        $requestedTab = (string)($_GET["tab"] ?? "questions");
        $tab = in_array($requestedTab, ["questions", "compose", "channels"], true) ? $requestedTab : "questions";
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "channel") {
                $channel = new Channel();
                $channel->channel = trim(strip_tags($data["channel"] ?? ""));
                $channel->description = trim(strip_tags($data["description"] ?? ""));
                $channel->save();
            } elseif ($data["action"] === "delete_channel") {
                $channel = (new Channel())->findById((int)($data["channel_id"] ?? 0));
                if (!$channel) { $this->jsonMessage("Canal não encontrado.", "warning"); return; }
                if ($channel->questions()->count()) { $this->jsonMessage("Mova ou exclua as perguntas antes de apagar este canal.", "warning"); return; }
                $channel->destroy();
            } elseif ($data["action"] === "question") {
                $supportLinkInput = trim(strip_tags($data["support_link"] ?? ""));
                $supportLink = $supportLinkInput !== "" ? $this->safeNotificationLink($supportLinkInput) : null;
                if ($supportLinkInput !== "" && $supportLink === null) { $this->jsonMessage("Use somente um link interno do site para o conteúdo de suporte.", "warning"); return; }
                $question = new Question();
                $question->channel_id = (int)($data["channel_id"] ?? 0);
                $question->question = trim(strip_tags($data["question"] ?? ""));
                $question->response = trim($data["response"] ?? "");
                $question->support_link = $supportLink;
                $question->order_by = (int)($data["order_by"] ?? 1);
                $question->save();
            } elseif ($data["action"] === "delete_question") {
                (new Question())->findById((int)($data["question_id"] ?? 0))?->destroy();
            }
            echo json_encode(["reload" => true]);
            return;
        }
        echo $this->view->render("components/faqs/studio", $this->viewData("Perguntas frequentes", "faqs", $user, [
            "tab" => $tab,
            "channels" => (new Channel())->find()->order("channel")->fetch(true) ?: [],
            "questions" => (new Question())->find()->order("channel_id, order_by, id")->fetch(true) ?: [],
            "supportArticles" => (new SupportArticle())->findPublished()->order("title")->fetch(true) ?: []
        ]));
    }

    public function support(): void
    {
        $user = $this->guard("support.manage");
        echo $this->view->render("components/support/home", $this->viewData("Central de Ajuda", "support", $user, [
            "categories" => (new SupportCategory())->find()->order("position, title")->fetch(true) ?: [],
            "articles" => (new SupportArticle())->find()->order("id DESC")->fetch(true) ?: []
        ]));
    }

    public function agenda(?array $data): void
    {
        $user = $this->guard("support.manage");
        $pdo = Connect::getInstance();
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if ($data["action"] === "delete") {
                $eventId = (int)($data["event_id"] ?? 0);
                if (!$eventId) { $this->jsonMessage("Compromisso inválido.", "warning"); return; }
                $stmt = $pdo->prepare("DELETE FROM studio_calendar_events WHERE id=:id");
                $stmt->execute(["id" => $eventId]);
                if (!$stmt->rowCount()) { $this->jsonMessage("Compromisso não encontrado.", "warning"); return; }
                \Source\Support\Audit::record("delete", "studio_calendar_events", $eventId);
                $this->message->success("Compromisso excluído.")->flash();
                echo json_encode(["redirect" => url("/helpdesk/agenda")]); return;
            }
            $title = trim(strip_tags((string)($data["title"] ?? "")));
            $startsAt = strtotime((string)($data["starts_at"] ?? ""));
            $endsAt = !empty($data["ends_at"]) ? strtotime((string)$data["ends_at"]) : null;
            $types = ["meeting", "task", "deadline", "support"];
            $statuses = ["scheduled", "completed", "cancelled"];
            if (mb_strlen($title) < 3 || !$startsAt || ($endsAt && $endsAt < $startsAt)) { $this->jsonMessage("Informe título e período válidos.", "warning"); return; }
            $eventId = (int)($data["event_id"] ?? 0);
            $assignedTo = ($id = (int)($data["assigned_to"] ?? 0)) ?: null;
            $startsAtSql = date("Y-m-d H:i:s", $startsAt);
            $eventType = in_array($data["type"] ?? "", $types, true) ? $data["type"] : "meeting";
            $eventStatus = in_array($data["status"] ?? "scheduled", $statuses, true) ? $data["status"] : "scheduled";
            $payload = ["title" => $title, "description" => trim(strip_tags((string)($data["description"] ?? ""))), "starts" => $startsAtSql, "ends" => $endsAt ? date("Y-m-d H:i:s", $endsAt) : null, "type" => $eventType, "status" => $eventStatus, "assigned" => $assignedTo];
            if ($eventId) {
                $exists = $pdo->prepare("SELECT id FROM studio_calendar_events WHERE id=:id");
                $exists->execute(["id" => $eventId]);
                if (!$exists->fetchColumn()) { $this->jsonMessage("Compromisso não encontrado.", "warning"); return; }
                $stmt = $pdo->prepare("UPDATE studio_calendar_events SET title=:title,description=:description,starts_at=:starts,ends_at=:ends,type=:type,status=:status,assigned_to=:assigned WHERE id=:id");
                $stmt->execute($payload + ["id" => $eventId]);
                \Source\Support\Audit::record("update", "studio_calendar_events", $eventId, [], ["title" => $title]);
                $this->message->success("Compromisso atualizado.")->flash();
            } else {
                $stmt = $pdo->prepare("INSERT INTO studio_calendar_events(title,description,starts_at,ends_at,type,status,assigned_to,created_by) VALUES(:title,:description,:starts,:ends,:type,:status,:assigned,:creator)");
                $stmt->execute($payload + ["creator" => $user->id]);
                $eventId = (int)$pdo->lastInsertId();
                $this->notifyAgendaEvent($eventId, $title, $startsAtSql, $eventType, $assignedTo, $user);
                \Source\Support\Audit::record("create", "studio_calendar_events", $eventId, [], ["title" => $title]);
                $this->message->success("Compromisso cadastrado.")->flash();
            }
            echo json_encode(["redirect" => url("/helpdesk/agenda")]); return;
        }
        $month = (string)($_GET["month"] ?? date("Y-m"));
        if (!preg_match("/^\\d{4}-(0[1-9]|1[0-2])$/", $month)) $month = date("Y-m");
        $from = $month . "-01 00:00:00";
        $to = date("Y-m-d H:i:s", strtotime($from . " +1 month"));
        $eventType = in_array($_GET["type"] ?? "", ["meeting", "task", "deadline", "support"], true) ? $_GET["type"] : "";
        $eventStatus = in_array($_GET["status"] ?? "", ["scheduled", "completed", "cancelled"], true) ? $_GET["status"] : "";
        $assignedFilter = filter_var($_GET["assigned_to"] ?? null, FILTER_VALIDATE_INT) ?: null;
        $terms = ["e.starts_at >= :from", "e.starts_at < :to"];
        $params = ["from" => $from, "to" => $to];
        if ($eventType) { $terms[] = "e.type=:type"; $params["type"] = $eventType; }
        if ($eventStatus) { $terms[] = "e.status=:status"; $params["status"] = $eventStatus; }
        if ($assignedFilter) { $terms[] = "e.assigned_to=:assigned"; $params["assigned"] = $assignedFilter; }
        $events = $pdo->prepare("SELECT e.*, CONCAT(u.first_name,' ',u.last_name) assigned_name FROM studio_calendar_events e LEFT JOIN users u ON u.id=e.assigned_to WHERE " . implode(" AND ", $terms) . " ORDER BY e.starts_at");
        $events->execute($params);
        echo $this->view->render("components/agenda/home", $this->viewData("Agenda", "agenda", $user, ["month" => $month, "events" => $events->fetchAll() ?: [], "users" => (new User())->find("status != :status", "status=trash")->order("first_name,last_name")->fetch(true) ?: [], "typeFilter" => $eventType, "statusFilter" => $eventStatus, "assignedFilter" => $assignedFilter]));
    }

    public function tickets(?array $data): void
    {
        $user = $this->guard("support.manage");
        $pdo = Connect::getInstance();
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            $ticketId = (int)($data["ticket_id"] ?? 0);
            if ($data['action'] === 'template_save') {
                $title=mb_substr(trim(strip_tags((string)($data['title']??''))),0,120);$body=trim(strip_tags((string)($data['body']??'')));
                if(mb_strlen($title)<2||mb_strlen($body)<2){$this->jsonMessage('Informe título e conteúdo da resposta rápida.','warning');return;}
                $stmt=$pdo->prepare('INSERT INTO studio_support_templates(title,body,created_by) VALUES(?,?,?)');$stmt->execute([$title,$body,$user->id]);
                \Source\Support\Audit::record('create','studio_support_templates',$pdo->lastInsertId(),[],['title'=>$title]);echo json_encode(['reload'=>true]);return;
            }
            if ($data['action'] === 'template_delete') {
                $templateId=(int)($data['template_id']??0);$stmt=$pdo->prepare('DELETE FROM studio_support_templates WHERE id=?');$stmt->execute([$templateId]);
                if(!$stmt->rowCount()){$this->jsonMessage('Resposta rápida não encontrada.','warning');return;}\Source\Support\Audit::record('delete','studio_support_templates',$templateId);echo json_encode(['reload'=>true]);return;
            }
            if ($data["action"] === "bulk") {
                $ticketIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['ticket_ids'] ?? [])))));
                $status = in_array($data['status'] ?? '', ["open", "in_progress", "waiting_customer", "resolved", "closed"], true) ? $data['status'] : null;
                if (!$ticketIds || !$status) { $this->jsonMessage('Selecione chamados e um status válido.', 'warning'); return; }
                $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
                $stmt = $pdo->prepare("UPDATE studio_support_tickets SET status=?,resolved_at=" . (in_array($status,['resolved','closed'],true) ? 'NOW()' : 'NULL') . " WHERE id IN ({$placeholders})");
                $stmt->execute(array_merge([$status], $ticketIds));
                foreach ($ticketIds as $id) { $event=$pdo->prepare("INSERT INTO studio_support_ticket_events(ticket_id,user_id,event_type,new_value) VALUES(?,?,?,?)"); $event->execute([$id,$user->id,'status',$status]); }
                echo json_encode(['reload'=>true,'affected'=>$stmt->rowCount()]); return;
            }
            if ($data["action"] === "update" && $ticketId) {
                $statuses = ["open", "in_progress", "waiting_customer", "resolved", "closed"];
                $status = in_array($data["status"] ?? "", $statuses, true) ? $data["status"] : "open";
                $current=$pdo->prepare("SELECT status,priority,assigned_to,team,category,tags FROM studio_support_tickets WHERE id=:id");$current->execute(['id'=>$ticketId]);$before=$current->fetch();
                if(!$before){$this->jsonMessage('Chamado não encontrado.','warning');return;}
                $priority=in_array($data['priority']??$before->priority,['low','medium','high','urgent'],true)?($data['priority']??$before->priority):$before->priority;
                $stmt = $pdo->prepare("UPDATE studio_support_tickets SET status=:status,priority=:priority,assigned_to=:assigned,team=:team,category=:category,tags=:tags,resolved_at=:resolved WHERE id=:id");
                $stmt->execute(["status" => $status,"priority"=>$priority,"assigned" => ($id = (int)($data["assigned_to"] ?? $before->assigned_to)) ?: null,"team"=>mb_substr(trim(strip_tags((string)($data['team']??$before->team))),0,100),"category"=>mb_substr(trim(strip_tags((string)($data['category']??$before->category))),0,100),"tags"=>mb_substr(trim(strip_tags((string)($data['tags']??$before->tags))),0,500), "resolved" => in_array($status, ["resolved", "closed"], true) ? date("Y-m-d H:i:s") : null, "id" => $ticketId]);
                foreach(['status','priority','assigned_to','team','category','tags'] as $field){$new=$field==='assigned_to'?(($id=(int)($data['assigned_to']??$before->$field))?:null):($field==='status'?$status:($field==='priority'?$priority:mb_substr(trim(strip_tags((string)($data[$field]??$before->$field))),0,$field==='tags'?500:100)));if((string)$before->$field!==(string)$new){$event=$pdo->prepare("INSERT INTO studio_support_ticket_events(ticket_id,user_id,event_type,old_value,new_value) VALUES(?,?,?,?,?)");$event->execute([$ticketId,$user->id,$field,(string)$before->$field,(string)$new]);}}
                echo json_encode(["redirect" => url("/helpdesk/tickets?ticket=".$ticketId)]); return;
            }
            if ($data["action"] === "reply" && $ticketId) {
                $reply = trim(strip_tags((string)($data["message"] ?? "")));
                if (mb_strlen($reply) < 2) { $this->jsonMessage("Escreva uma resposta antes de enviar.", "warning"); return; }
                $exists = $pdo->prepare("SELECT id FROM studio_support_tickets WHERE id=:id"); $exists->execute(["id" => $ticketId]);
                if (!$exists->fetch()) { $this->jsonMessage("Chamado não encontrado.", "warning"); return; }
                $message = $pdo->prepare("INSERT INTO studio_support_ticket_messages(ticket_id,user_id,message,is_internal) VALUES(:ticket,:user,:message,:internal)");
                $message->execute(["ticket" => $ticketId, "user" => $user->id, "message" => $reply, "internal" => !empty($data["is_internal"]) ? 1 : 0]);
                $messageId=(int)$pdo->lastInsertId();
                $timeSpent=max(0,min(86400,(int)($data['time_spent']??0)));$nextStatus=!empty($data['resolve_after'])?'resolved':null;
                $pdo->prepare("UPDATE studio_support_tickets SET status=".($nextStatus?"'resolved'":"IF(status='open','in_progress',status)").",first_response_at=COALESCE(first_response_at,NOW()),work_seconds=work_seconds+:seconds,resolved_at=".($nextStatus?'NOW()':'resolved_at')." WHERE id=:id")->execute(["seconds"=>$timeSpent,"id" => $ticketId]);
                $files=$_FILES['attachments']??null;if($files&&!empty($files['tmp_name'])&&is_array($files['tmp_name'])){foreach($files['tmp_name'] as $index=>$temporary){if(!$temporary)continue;$file=['name'=>$files['name'][$index]??'arquivo','type'=>$files['type'][$index]??'','tmp_name'=>$temporary,'error'=>$files['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$index]??0];$upload=new Upload();$path=$upload->file($file,'ticket-'.$ticketId.'-'.bin2hex(random_bytes(4)));if(!$path){$this->jsonMessage('Um dos anexos não é válido.','warning');return;}$attachment=$pdo->prepare("INSERT INTO studio_support_ticket_attachments(ticket_id,message_id,user_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?,?,?)");$attachment->execute([$ticketId,$messageId,$user->id,$path,mb_substr(basename((string)$file['name']),0,255),$file['type']?:null,(int)$file['size']]);}}
                echo json_encode(["redirect" => url("/helpdesk/tickets?ticket=" . $ticketId)]); return;
            }
            $subject = trim(strip_tags((string)($data["subject"] ?? "")));
            $message = trim(strip_tags((string)($data["message"] ?? "")));
            $areas = ["general", "technical", "financial"];
            $priorities = ["low", "medium", "high", "urgent"];
            if (mb_strlen($subject) < 4 || mb_strlen($message) < 10) { $this->jsonMessage("Informe assunto e uma descrição com pelo menos 10 caracteres.", "warning"); return; }
            // A coluna protocol possui 12 caracteres: CH + data (aammdd) + 4 caracteres aleatórios.
            $protocol = "CH" . date("ymd") . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $priority = in_array($data["priority"] ?? "", $priorities, true) ? $data["priority"] : "medium";
            $assignedTo = ($id = (int)($data["assigned_to"] ?? 0)) ?: null;
            $dueAt = date("Y-m-d H:i:s", strtotime("+" . $this->ticketSlaHours($priority) . " hours"));
            $requesterId = ($id = (int)($data["requester_id"] ?? 0)) ?: $user->id;
            if (!(new User())->findById($requesterId)) { $this->jsonMessage("Selecione um solicitante válido.", "warning"); return; }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO studio_support_tickets(protocol,subject,message,area,priority,requester_id,assigned_to,created_by,due_at) VALUES(:protocol,:subject,:message,:area,:priority,:requester,:assigned,:creator,:due_at)");
                $stmt->execute(["protocol" => $protocol, "subject" => $subject, "message" => $message, "area" => in_array($data["area"] ?? "", $areas, true) ? $data["area"] : "general", "priority" => $priority, "requester" => $requesterId, "assigned" => $assignedTo, "creator" => $user->id, "due_at" => $dueAt]);
                $newTicketId = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                \Source\Support\AppLogger::exception($exception, "tickets", ["event_type" => "ticket_create_failed", "users_id" => $user->id]);
                $this->jsonMessage("Não foi possível cadastrar o chamado. Tente novamente.", "error"); return;
            }
            try { $this->notifyTicketOpened($newTicketId, $protocol, $subject, $priority, $dueAt, $assignedTo, $requesterId); }
            catch (\Throwable $exception) { \Source\Support\AppLogger::exception($exception, "tickets", ["event_type" => "ticket_notification_failed", "ticket_id" => $newTicketId]); }
            \Source\Support\Audit::record("create", "studio_support_tickets", $newTicketId, [], ["protocol" => $protocol, "subject" => $subject, "priority" => $priority, "due_at" => $dueAt]);
            $this->message->success("Chamado {$protocol} cadastrado.")->flash();
            echo json_encode(["redirect" => url("/helpdesk/tickets")]); return;
        }
        $status = (string)($_GET["status"] ?? "");
        $search = trim(strip_tags((string)($_GET["q"] ?? "")));
        $priorityFilter=in_array($_GET['priority']??'', ['low','medium','high','urgent'],true)?$_GET['priority']:'';
        $assignedFilter=(string)($_GET['assigned_to']??'');$dueFilter=in_array($_GET['due']??'', ['today','overdue'],true)?$_GET['due']:'';
        $terms = []; $params = [];
        if (in_array($status, ["open", "in_progress", "waiting_customer", "resolved", "closed"], true)) { $terms[] = "t.status=:status"; $params["status"] = $status; }
        if ($search !== "") { $terms[] = "(t.subject LIKE :q OR t.protocol LIKE :q OR t.requester_name LIKE :q OR t.requester_email LIKE :q OR CONCAT(r.first_name,' ',r.last_name) LIKE :q)"; $params["q"] = "%{$search}%"; }
        if($priorityFilter){$terms[]='t.priority=:priority';$params['priority']=$priorityFilter;}
        if($assignedFilter==='unassigned'){$terms[]='t.assigned_to IS NULL';}elseif(ctype_digit($assignedFilter)&&((int)$assignedFilter)>0){$terms[]='t.assigned_to=:assigned_filter';$params['assigned_filter']=(int)$assignedFilter;}
        if($dueFilter==='today'){$terms[]='DATE(t.due_at)=CURDATE()';}elseif($dueFilter==='overdue'){$terms[]="t.due_at<NOW() AND t.status NOT IN ('resolved','closed')";}
        $query = "SELECT t.*, COALESCE(NULLIF(TRIM(CONCAT(r.first_name,' ',r.last_name)),''),t.requester_name,'Solicitante') requester_name, CONCAT(a.first_name,' ',a.last_name) assigned_name FROM studio_support_tickets t LEFT JOIN users r ON r.id=t.requester_id LEFT JOIN users a ON a.id=t.assigned_to" . ($terms ? " WHERE " . implode(" AND ", $terms) : "") . " ORDER BY FIELD(t.status,'open','in_progress','waiting_customer','resolved','closed'), FIELD(t.priority,'urgent','high','medium','low'), t.created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query); $stmt->execute($params);
        $counts = $pdo->query("SELECT status,COUNT(*) total FROM studio_support_tickets GROUP BY status")->fetchAll() ?: [];
        $statusCounts = array_fill_keys(["open","in_progress","waiting_customer","resolved","closed"], 0); foreach ($counts as $row) $statusCounts[$row->status] = (int)$row->total;
        $ticketId = (int)($_GET["ticket"] ?? 0);
        $selectedTicket = null; $messages = []; $events=[]; $attachments=[];
        if ($ticketId) {
            $selected = $pdo->prepare("SELECT t.*,COALESCE(NULLIF(TRIM(CONCAT(r.first_name,' ',r.last_name)),''),t.requester_name,'Solicitante') requester_name,CONCAT(a.first_name,' ',a.last_name) assigned_name FROM studio_support_tickets t LEFT JOIN users r ON r.id=t.requester_id LEFT JOIN users a ON a.id=t.assigned_to WHERE t.id=:id");
            $selected->execute(["id" => $ticketId]); $selectedTicket = $selected->fetch() ?: null;
            if ($selectedTicket) { $history = $pdo->prepare("SELECT m.*,CONCAT(u.first_name,' ',u.last_name) user_name FROM studio_support_ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:ticket ORDER BY m.created_at"); $history->execute(["ticket" => $ticketId]); $messages = $history->fetchAll() ?: []; $eventStmt=$pdo->prepare("SELECT e.*,CONCAT(u.first_name,' ',u.last_name) user_name FROM studio_support_ticket_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.ticket_id=:ticket ORDER BY e.created_at");$eventStmt->execute(['ticket'=>$ticketId]);$events=$eventStmt->fetchAll()?:[];$attachmentStmt=$pdo->prepare("SELECT * FROM studio_support_ticket_attachments WHERE ticket_id=:ticket ORDER BY created_at");$attachmentStmt->execute(['ticket'=>$ticketId]);$attachments=$attachmentStmt->fetchAll()?:[]; }
        }
        $templates=$pdo->query("SELECT * FROM studio_support_templates WHERE active=1 ORDER BY title")->fetchAll()?:[];
        echo $this->view->render("components/tickets/home", $this->viewData("Chamados", "tickets", $user, ["tickets" => $stmt->fetchAll() ?: [], "users" => (new User())->find("status != :status", "status=trash")->order("first_name,last_name")->fetch(true) ?: [], "status" => $status, "search" => $search,"priorityFilter"=>$priorityFilter,"assignedFilter"=>$assignedFilter,"dueFilter"=>$dueFilter, "counts" => $statusCounts, "selectedTicket" => $selectedTicket, "messages" => $messages,"events"=>$events,"attachments"=>$attachments,"templates"=>$templates, "settings" => Settings::dados()]));
    }

    private function ticketSlaHours(string $priority): int
    {
        return ["urgent" => 24, "high" => 48, "medium" => 72, "low" => 120][$priority] ?? 72;
    }

    private function notifyTicketOpened(int $ticketId, string $protocol, string $subject, string $priority, string $dueAt, ?int $assignedTo, int $requesterId): void
    {
        $recipientIds = [$requesterId, $assignedTo];
        foreach ($this->supportTeamRecipients() as $teamMember) $recipientIds[] = (int)$teamMember->id;
        $category = $this->studioNotificationCategory();
        $priorityLabel = ["urgent" => "Urgente", "high" => "Alta", "medium" => "Média", "low" => "Baixa"][$priority] ?? "Média";
        foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
            $recipient = (new User())->findById((int)$recipientId);
            if (!$recipient) continue;
            if ($category) {
                $notification = new Notification();
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = "images/default.svg";
                $notification->title = "Novo chamado {$protocol}";
                $notification->body = "{$priorityLabel}: {$subject}. SLA até " . date_fmt($dueAt, "d/m H:i");
                $notification->severity = in_array($priority, ["urgent", "high"], true) ? "warning" : "info";
                $notification->link = url("/helpdesk/tickets?ticket={$ticketId}");
                $notification->view = 0;
                $notification->save();
            }
            if (is_email((string)$recipient->email)) {
                $body = "<p>Olá, " . htmlspecialchars($recipient->first_name, ENT_QUOTES, "UTF-8") . ".</p><p>Foi aberto o chamado <strong>" . htmlspecialchars($protocol, ENT_QUOTES, "UTF-8") . "</strong>: " . htmlspecialchars($subject, ENT_QUOTES, "UTF-8") . ".</p><p>Prioridade: <strong>{$priorityLabel}</strong>. Prazo de atendimento: <strong>" . date_fmt($dueAt, "d/m/Y H:i") . "</strong>.</p><p><a href=\"" . htmlspecialchars(url("/helpdesk/tickets?ticket={$ticketId}"), ENT_QUOTES, "UTF-8") . "\">Abrir chamado no MovesOS</a></p>";
                (new Email())->bootstrap("[{$protocol}] Novo chamado: {$subject}", $body, $recipient->email, $recipient->fullName())->queue(CONF_MAIL_SENDER["address"], CONF_MAIL_SENDER["name"], date("Y-m-d H:i:s"), null, $recipient->id);
            }
        }
    }

    private function notifyAgendaEvent(int $eventId, string $title, string $startsAt, string $type, ?int $assignedTo, User $creator): void
    {
        $recipientIds = [(int)$creator->id, $assignedTo];
        foreach ($this->supportTeamRecipients() as $teamMember) $recipientIds[] = (int)$teamMember->id;
        $category = $this->studioNotificationCategory();
        $typeLabel = ["meeting" => "Reunião", "task" => "Tarefa", "deadline" => "Prazo", "support" => "Atendimento"][$type] ?? "Compromisso";
        foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
            $recipient = (new User())->findById((int)$recipientId);
            if (!$recipient) continue;
            if ($category) {
                $notification = new Notification();
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = "images/default.svg";
                $notification->title = "Novo compromisso na agenda";
                $notification->body = "{$typeLabel}: {$title} em " . date_fmt($startsAt, "d/m/Y H:i");
                $notification->severity = $type === "deadline" ? "warning" : "info";
                $notification->link = url("/helpdesk/agenda?month=" . date("Y-m", strtotime($startsAt)));
                $notification->view = 0;
                $notification->save();
            }
            if (is_email((string)$recipient->email)) {
                $body = "<p>Olá, " . htmlspecialchars($recipient->first_name, ENT_QUOTES, "UTF-8") . ".</p><p>Há um novo compromisso na agenda: <strong>" . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . "</strong>.</p><p>Tipo: <strong>{$typeLabel}</strong>. Data: <strong>" . date_fmt($startsAt, "d/m/Y H:i") . "</strong>.</p><p><a href=\"" . htmlspecialchars(url("/helpdesk/agenda?month=" . date("Y-m", strtotime($startsAt))), ENT_QUOTES, "UTF-8") . "\">Abrir agenda no MovesOS</a></p>";
                (new Email())->bootstrap("Novo compromisso: {$title}", $body, $recipient->email, $recipient->fullName())->queue(CONF_MAIL_SENDER["address"], CONF_MAIL_SENDER["name"], date("Y-m-d H:i:s"), null, $recipient->id);
            }
        }
    }

    private function studioNotificationCategory(): ?NotificationCategory
    {
        $category = (new NotificationCategory())->findByUri("studio");
        if ($category) return $category;
        $category = new NotificationCategory();
        $category->title = "Studio";
        $category->uri = "studio";
        $category->description = "Avisos operacionais do Studio";
        $category->type = "system";
        return $category->save() ? $category : null;
    }

    /** @return array<int, User> */
    private function supportTeamRecipients(): array
    {
        // Níveis 5 e 10 correspondem, respectivamente, a administradores e desenvolvedores.
        return (new User())->find("level >= :level", "level=" . self::ADMIN_LEVEL)->fetch(true) ?: [];
    }

    public function supportCategory(?array $data): void
    {
        $user = $this->guard("support.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $category = $id ? (new SupportCategory())->findById($id) : null;
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if ($data["action"] === "delete") {
                if (!$category || $category->articles()->count()) { $this->jsonMessage("A categoria possui matérias ou não foi encontrada.", "warning"); return; }
                $category->destroy();
                echo json_encode(["redirect" => url("/helpdesk/support")]); return;
            }
            $title = trim(strip_tags($data["title"] ?? ""));
            if (mb_strlen($title) < 3) { $this->jsonMessage("Informe um nome válido para a categoria.", "warning"); return; }
            $category = $category ?: new SupportCategory();
            $category->title = $title;
            $category->uri = str_slug(trim(strip_tags($data["uri"] ?? "")) ?: $title);
            $category->description = trim(strip_tags($data["description"] ?? ""));
            $category->icon = preg_match("~^[a-z0-9-]+$~", $data["icon"] ?? "") ? $data["icon"] : "help-circle-outline";
            $category->position = max(1, (int)($data["position"] ?? 1));
            $category->status = ($data["status"] ?? "active") === "inactive" ? "inactive" : "active";
            if (!$category->save()) { echo json_encode(["message" => $category->message()->render()]); return; }
            echo json_encode(["redirect" => url("/helpdesk/support")]); return;
        }
        echo $this->view->render("components/support/category", $this->viewData($category ? "Editar categoria" : "Nova categoria", "support", $user, ["category" => $category]));
    }

    public function supportArticle(?array $data): void
    {
        $user = $this->guard("support.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $article = $id ? (new SupportArticle())->findById($id) : null;
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if ($data["action"] === "delete") {
                if (!$article) { $this->jsonMessage("Matéria não encontrada.", "warning"); return; }
                $cover = $article->cover ? dirname(__DIR__, 3) . "/" . CONF_UPLOAD_DIR . "/" . $article->cover : null;
                $article->destroy();
                if ($cover) (new Upload())->remove($cover);
                echo json_encode(["redirect" => url("/helpdesk/support")]); return;
            }
            $title = trim(strip_tags($data["title"] ?? ""));
            $summary = trim(strip_tags($data["summary"] ?? ""));
            $content = trim((string)($data["content"] ?? ""));
            $categoryId = filter_var($data["category_id"] ?? null, FILTER_VALIDATE_INT);
            if (mb_strlen($title) < 5 || mb_strlen($summary) < 10 || mb_strlen(strip_tags($content)) < 30 || !$categoryId || !(new SupportCategory())->findById($categoryId)) { $this->jsonMessage("Preencha título, resumo, conteúdo e categoria válidos.", "warning"); return; }
            $article = $article ?: new SupportArticle();
            $article->category_id = $categoryId;
            $article->author_id = $user->id;
            $article->title = $title;
            $article->uri = str_slug(trim(strip_tags($data["uri"] ?? "")) ?: $title);
            $article->summary = $summary;
            $article->content = $content;
            $article->status = ($data["status"] ?? "draft") === "published" ? "published" : "draft";
            $article->published_at = !empty($data["published_at"]) ? date("Y-m-d H:i:s", strtotime($data["published_at"])) : date("Y-m-d H:i:s");
            $libraryCover = $this->safeMediaSelection((string)($data["library_cover"] ?? ""));
            if ($libraryCover) $article->cover = $libraryCover;
            if (!empty($_FILES["cover"]["tmp_name"])) {
                $uploaded = (new Upload())->image($_FILES["cover"], "suporte-" . $article->uri . "-" . time(), 1600);
                if ($uploaded) $article->cover = $uploaded;
            }
            if (!$article->save()) { echo json_encode(["message" => $article->message()->render()]); return; }
            echo json_encode(["redirect" => url("/helpdesk/support/article/{$article->id}")]); return;
        }
        echo $this->view->render("components/support/article", $this->viewData($article ? "Editar matéria" : "Nova matéria", "support", $user, [
            "article" => $article, "categories" => (new SupportCategory())->find("status = :status", "status=active")->order("position, title")->fetch(true) ?: []
        ]));
    }

    public function supportImage(?array $data): void
    {
        $this->guard("support.manage");
        if (!csrf_verify($data ?? [])) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
        if (empty($_FILES["image"]["tmp_name"])) { $this->jsonMessage("Selecione uma imagem.", "warning"); return; }
        $uploaded = (new Upload())->image($_FILES["image"], "ajuda-" . time(), 1600);
        if (!$uploaded) { $this->jsonMessage("Não foi possível enviar a imagem.", "error"); return; }
        echo json_encode(["mce_image" => '<img src="' . url("/" . CONF_UPLOAD_DIR . "/" . $uploaded) . '" alt="Imagem da matéria de suporte">']);
    }

    public function slides(): void
    {
        $user = $this->guard("slides.manage");
        echo $this->view->render("components/slides/home", $this->viewData("Banners e destaques", "slides", $user, [
            "slides" => (new AppSlides())->find()->order("id DESC")->fetch(true) ?: []
        ]));
    }

    public function slide(?array $data): void
    {
        $user = $this->guard("slides.manage");
        $id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $slide = $id ? (new AppSlides())->findById($id) : null;
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "delete") {
                (new AppSlides())->findById((int)($data["slide_id"] ?? 0))?->destroy();
                echo json_encode(["redirect" => url("/studio/slides")]);
                return;
            }
            $slide = $slide ?: new AppSlides();
            $slide->title = trim(strip_tags($data["title"] ?? ""));
            $slide->uri = str_slug($slide->title);
            $slide->content = $data["content"] ?? "";
            $slide->author = $user->id;
            $slide->status = in_array(($data["status"] ?? "draft"), ["post", "draft", "trash"], true) ? $data["status"] : "draft";
            $slide->position = in_array(($data["position"] ?? "left"), ["left", "center", "right"], true) ? $data["position"] : "left";
            $slide->post_at = date("Y-m-d H:i:s");
            $libraryCover = $this->safeMediaSelection((string)($data["library_cover"] ?? ""));
            if ($libraryCover) $slide->cover = $libraryCover;
            if (!empty($_FILES["cover"]["tmp_name"])) {
                $cover = (new Upload())->image($_FILES["cover"], $slide->uri, 1920);
                if ($cover) $slide->cover = $cover;
            }
            if (!$slide->save()) {
                echo json_encode(["message" => $slide->message()->render()]);
                return;
            }
            echo json_encode(["redirect" => url("/studio/slide/{$slide->id}")]);
            return;
        }
        echo $this->view->render("components/slides/form", $this->viewData($slide ? "Editar destaque" : "Novo destaque", "slides", $user, ["slide" => $slide]));
    }

    public function reports(): void
    {
        $user = $this->guard("reports.view");
        echo $this->view->render("components/reports/home", $this->viewData("Relatórios", "reports", $user, [
            "access" => (new Access())->find()->order("created_at DESC")->limit(30)->fetch(true) ?: [],
            "online" => (new Online())->findByActive() ?: []
        ]));
    }

    public function versions(?array $data): void
    {
        $user = $this->guard("settings.manage");
        $this->ensureVersionStorage();
        $pdo = Connect::getInstance();
        if (($data["action"] ?? null) === "release") {
            if (!csrf_verify($data ?? [])) { $this->jsonMessage("Sessão expirada. Atualize a página.", "error"); return; }
            if (!$user->can("system.manage")) { $this->jsonMessage("Somente desenvolvedores podem publicar versões.", "error"); return; }
            $version = trim((string)($data["version"] ?? ""));
            $product = in_array($data["product"] ?? "", ["web", "app", "studio", "erp", "support"], true) ? $data["product"] : "studio";
            $name = mb_substr(trim(strip_tags((string)($data["name"] ?? ""))), 0, 120);
            $notes = trim(strip_tags((string)($data["notes"] ?? "")));
            if (!preg_match("~^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-[0-9A-Za-z.-]+)?$~", $version)) { $this->jsonMessage("Use uma versão semântica, por exemplo 1.2.0.", "warning"); return; }
            if (mb_strlen($name) < 3 || mb_strlen($notes) < 10) { $this->jsonMessage("Informe um nome e descreva as alterações da versão.", "warning"); return; }
            try {
                $pdo->beginTransaction();
                $archive = $pdo->prepare("UPDATE movesos_versions SET status='archived' WHERE product=:product AND status='current'");
                $archive->execute(["product" => $product]);
                $stmt = $pdo->prepare("INSERT INTO movesos_versions (product,version,name,notes,status,created_by,published_at) VALUES (:product,:version,:name,:notes,'current',:user,NOW())");
                $stmt->execute(["product" => $product, "version" => $version, "name" => $name, "notes" => $notes, "user" => $user->id]);
                $releaseId = (int)$pdo->lastInsertId();
                $pdo->commit();
                \Source\Support\Audit::record("create", "movesos_versions", $releaseId, [], ["product" => $product, "version" => $version, "name" => $name]);
                $this->message->success(ucfirst($product) . " {$version} publicado com sucesso.")->flash();
                echo json_encode(["reload" => true]);
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                \Source\Support\AppLogger::exception($exception, "versioning", ["event_type" => "release_publish_failed", "version" => $version]);
                $this->jsonMessage("A versão já existe ou não pôde ser publicada.", "error");
            }
            return;
        }
        $product = in_array($_GET["product"] ?? "", ["web", "app", "studio", "erp", "support"], true) ? $_GET["product"] : "studio";
        $stmt = $pdo->prepare("SELECT v.*,CONCAT(u.first_name,' ',u.last_name) author_name FROM movesos_versions v LEFT JOIN users u ON u.id=v.created_by WHERE v.product=:product ORDER BY v.id DESC LIMIT 50");
        $stmt->execute(["product" => $product]);
        $versions = $stmt->fetchAll() ?: [];
        $currentVersions = [];
        foreach ($pdo->query("SELECT product,version FROM movesos_versions WHERE status='current'")->fetchAll() as $current) { $currentVersions[$current->product] = $current->version; }
        echo $this->view->render("components/versions/home", $this->viewData("Versões", "versions", $user, ["versions" => $versions, "product" => $product, "currentVersions" => $currentVersions]));
    }

    public function systemLogs(?array $data): void
    {
        $user = $this->guardDeveloper();
        $pdo = Connect::getInstance();
        $levels = ['debug','info','notice','warning','error','critical','alert','emergency'];
        $statuses = ['open','resolved','ignored'];
        $level = in_array($_GET['level'] ?? '', $levels, true) ? $_GET['level'] : '';
        $status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
        $channel = mb_substr(trim(strip_tags((string)($_GET['channel'] ?? ''))), 0, 80);
        $search = mb_substr(trim(strip_tags((string)($_GET['q'] ?? ''))), 0, 120);
        $periodInput = (string)($_GET['period'] ?? '30');
        $period = in_array($periodInput, ['today','7','30','90','all'], true) ? $periodInput : '30';
        $dateFrom = $this->validFilterDate((string)($_GET['date_from'] ?? ''));
        $dateTo = $this->validFilterDate((string)($_GET['date_to'] ?? ''));
        if (!$dateFrom && !$dateTo && $period !== 'all') {
            $dateTo = date('Y-m-d');
            $dateFrom = $period === 'today' ? $dateTo : date('Y-m-d', strtotime('-' . ((int)$period - 1) . ' days'));
        }
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }
        $sortInput = (string)($_GET['sort'] ?? 'newest');
        $sort = in_array($sortInput, ['newest','oldest','occurrences'], true) ? $sortInput : 'newest';
        $perPageInput = (int)($_GET['per_page'] ?? 30);
        $perPage = in_array($perPageInput, [15,30,60,100], true) ? $perPageInput : 30;
        $where = [];
        $params = [];
        if ($level !== '') { $where[] = 'l.level=:level'; $params['level'] = $level; }
        if ($status !== '') { $where[] = 'l.status=:status'; $params['status'] = $status; }
        if ($channel !== '') { $where[] = 'l.channel=:channel'; $params['channel'] = $channel; }
        if ($search !== '') { $where[] = '(l.incident_id LIKE :search OR l.msg LIKE :search OR l.code LIKE :search OR l.url LIKE :search)'; $params['search'] = "%{$search}%"; }
        if ($dateFrom) { $where[] = 'l.last_seen_at >= :date_from'; $params['date_from'] = $dateFrom . ' 00:00:00'; }
        if ($dateTo) { $where[] = 'l.last_seen_at < :date_to'; $params['date_to'] = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day')); }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $pdo->prepare("SELECT COUNT(*) FROM app_log l{$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $page = max(1, (int)($data['page'] ?? $_GET['page'] ?? 1));
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $orderSql = match ($sort) { 'oldest' => 'l.last_seen_at ASC,l.id ASC', 'occurrences' => 'l.occurrences DESC,l.last_seen_at DESC', default => "FIELD(l.status,'open','ignored','resolved'),l.last_seen_at DESC,l.id DESC" };
        $query = $pdo->prepare("SELECT l.*,CONCAT(u.first_name,' ',u.last_name) user_name,CONCAT(r.first_name,' ',r.last_name) resolver_name FROM app_log l LEFT JOIN users u ON u.id=l.users_id LEFT JOIN users r ON r.id=l.resolved_by{$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($params);
        $summary = $pdo->query("SELECT COUNT(*) total,SUM(status='open') opened,SUM(level IN ('error','critical','alert','emergency') AND status='open') criticals,SUM(last_seen_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)) today FROM app_log")->fetch();
        $channels = $pdo->query("SELECT DISTINCT channel FROM app_log WHERE channel<>'' ORDER BY channel")->fetchAll();
        $queryString = http_build_query(array_filter(['level' => $level, 'status' => $status, 'channel' => $channel, 'q' => $search, 'period' => $period !== '30' ? $period : '', 'date_from' => !empty($_GET['date_from']) ? $dateFrom : '', 'date_to' => !empty($_GET['date_to']) ? $dateTo : '', 'sort' => $sort !== 'newest' ? $sort : '', 'per_page' => $perPage !== 30 ? $perPage : ''], static fn($value) => $value !== ''));

        echo $this->view->render('components/logs/home', $this->viewData('Log', 'system-logs', $user, [
            'logs' => $query->fetchAll(), 'summary' => $summary, 'channels' => $channels,
            'filters' => compact('level','status','channel','search','period','dateFrom','dateTo','sort','perPage'), 'page' => $page, 'pages' => $pages,
            'total' => $total, 'queryString' => $queryString
        ]));
    }

    public function systemLogAction(array $data): void
    {
        $user = $this->guardDeveloper();
        if (!csrf_verify($data)) { $this->jsonMessage('Sessão expirada. Atualize a página.', 'error'); return; }
        $action = $data['action'] ?? '';
        $status = ['resolve' => 'resolved', 'ignore' => 'ignored', 'reopen' => 'open'][$action] ?? null;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['log_ids'] ?? [])))));
        $routeId = (int)($data['id'] ?? 0);
        if ($routeId) $ids[] = $routeId;
        $ids = array_values(array_unique($ids));
        if (!$ids || (!$status && $action !== 'delete')) { $this->jsonMessage('Selecione ao menos um log e uma ação válida.', 'warning'); return; }

        $pdo = Connect::getInstance();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $pdo->beginTransaction();
            if ($action === 'delete') {
                // Logs ainda abertos não podem ser apagados: preservamos incidentes sem tratativa.
                $stmt = $pdo->prepare("DELETE FROM app_log WHERE id IN ({$placeholders}) AND status IN ('resolved','ignored')");
                $stmt->execute($ids);
            } elseif ($status === 'open') {
                $stmt = $pdo->prepare("UPDATE app_log SET status='open',resolved_by=NULL,resolved_at=NULL,updated_at=NOW() WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
            } else {
                $stmt = $pdo->prepare("UPDATE app_log SET status=?,resolved_by=?,resolved_at=NOW(),updated_at=NOW() WHERE id IN ({$placeholders})");
                $stmt->execute(array_merge([$status, (int)$user->id], $ids));
            }
            $affected = $stmt->rowCount();
            if (!$affected) { $pdo->rollBack(); $this->jsonMessage($action === 'delete' ? 'Somente logs resolvidos ou ignorados podem ser excluídos.' : 'Nenhum log foi alterado.', 'warning'); return; }
            $pdo->commit();
            foreach ($ids as $id) \Source\Support\Audit::record($action === 'delete' ? 'delete' : 'update', 'app_log_status', $id, [], ['status' => $status, 'batch' => count($ids) > 1]);
            echo json_encode(['reload' => true, 'affected' => $affected]);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            \Source\Support\AppLogger::exception($exception, 'logs', ['event_type' => 'log_action_failed', 'action' => $action]);
            $this->jsonMessage('Não foi possível atualizar os logs.', 'error');
        }
    }

    public function settings(?array $data): void
    {
        $user = $this->guard("settings.manage");
        $this->ensureVersionStorage();
        $settings = Settings::dados();
        if (!empty($data["action"])) {
            if (!csrf_verify($data)) {
                $this->jsonMessage("Sessão expirada. Atualize a página.", "error");
                return;
            }
            if ($data["action"] === "access") {
                if (!$user->can("system.manage")) { $this->jsonMessage("Somente a administração global pode bloquear módulos.", "error"); return; }
                $module = $data["module"] ?? "";
                $column = [
                    "studio" => "access_studio",
                    "erp" => "access_erp",
                    "app" => "access_app",
                    "site" => "access_site",
                    "support" => "access_support"
                ][$module] ?? null;
                if (!$column) { $this->jsonMessage("Módulo inválido.", "warning"); return; }
                $settings->{$column} = !empty($data["enabled"]) ? 1 : 0;
                if (!$settings->save()) { echo json_encode(["message" => $settings->message()->render()]); return; }
                AccessControl::clear();
                echo json_encode(["reload" => true]);
                return;
            }
            if ($data["action"] === "release") {
                if (!$user->can("system.manage")) { $this->jsonMessage("Somente a administração global pode publicar versões.", "error"); return; }
                $version = trim((string)($data["version"] ?? ""));
                $product = in_array($data["product"] ?? "", ["web", "app", "studio", "erp", "support"], true) ? $data["product"] : "studio";
                if (!preg_match("~^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-[0-9A-Za-z.-]+)?$~", $version)) { $this->jsonMessage("Use uma versão semântica, por exemplo 1.2.0.", "warning"); return; }
                $pdo = Connect::getInstance();
                try {
                    $pdo->beginTransaction();
                    $archive = $pdo->prepare("UPDATE movesos_versions SET status='archived' WHERE product=:product AND status='current'");
                    $archive->execute(["product" => $product]);
                    $stmt = $pdo->prepare("INSERT INTO movesos_versions (product,version,name,notes,status,created_by,published_at) VALUES (:product,:version,:name,:notes,'current',:user,NOW())");
                    $stmt->execute(["product" => $product, "version" => $version, "name" => trim(strip_tags($data["name"] ?? "")), "notes" => trim(strip_tags($data["notes"] ?? "")), "user" => $user->id]);
                    $pdo->commit();
                    \Source\Support\Audit::record("create", "movesos_versions", $pdo->lastInsertId(), [], ["version" => $version, "name" => $data["name"] ?? ""]);
                    echo json_encode(["reload" => true]);
                } catch (\Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    \Source\Support\AppLogger::exception($exception, 'versioning', ['event_type' => 'release_publish_failed', 'version' => $version]);
                    $this->jsonMessage("Esta versão já existe ou não pôde ser publicada.", "error");
                }
                return;
            }
            $themeDirectories = $this->templateDirectories(dirname(__DIR__, 3) . "/container/web");
            $supportThemes = array_values(array_filter($themeDirectories, fn($name) => str_contains($name, "support")));
            $siteThemes = array_values(array_diff($themeDirectories, $supportThemes));
            $appThemes = $this->templateDirectories(dirname(__DIR__, 3) . "/container/apps/residents");
            $erpThemes = $this->templateDirectories(dirname(__DIR__, 3) . "/container/apps/erp");
            foreach (["view_theme" => $siteThemes, "view_support" => $supportThemes, "view_app" => $appThemes, "view_erp" => $erpThemes] as $field => $available) {
                if (!array_key_exists($field, $data)) continue;
                if (!in_array($data[$field] ?? "", $available, true)) { $this->jsonMessage("O template selecionado em {$field} não existe.", "warning"); return; }
            }
            $fields = ["mode","site_name","site_title","site_desc","site_photo","site_logo_svg","site_icon","site_favicon","site_lang","site_domain","site_domain_ssl","site_domain_off","site_street","site_phone","site_whatsapp","site_number","site_complement","site_city","site_state","site_code","site_district","view_theme","view_support","view_app","view_erp","view_admin","view_mail","view_upkeep","upload_dir","upload_image","upload_file","upload_media","image_cache","image_size","image_jpg","image_png","mail_host","mail_port","mail_user","mail_pass","mail_name","mail_address","mail_suport","mail_lang","mail_html","mail_auth","mail_secure","mail_charset","pay_mode","pay_live","pay_test","pay_back","social_tw_creator","social_tw_publisher","social_fb_app","social_fb_page","social_fb_author","social_google_page","social_google_author","social_instagram_page","social_youtube_page","social_linkedin_page","timezone_set"];
            $secrets = ["mail_user", "mail_pass", "pay_live", "pay_test"];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $data)) continue;
                if (in_array($field, $secrets, true) && trim((string)($data[$field] ?? "")) === "") continue;
                $value = trim(strip_tags((string)($data[$field] ?? "")));
                $settings->{$field} = $field === "mode" ? (in_array((int)$value, [1,2], true) ? (int)$value : 1) : $value;
            }
            if (!empty($_FILES["site_logo_svg_file"]["tmp_name"])) {
                $svg = $this->safeSvgUpload($_FILES["site_logo_svg_file"]);
                if (!$svg) { $this->jsonMessage("O SVG é inválido ou contém elementos não permitidos.", "error"); return; }
                $settings->site_logo_svg = $svg;
            }
            foreach (["site_icon_file" => ["site_icon", 512], "site_favicon_file" => ["site_favicon", 256]] as $input => [$field, $width]) {
                if (empty($_FILES[$input]["tmp_name"])) continue;
                $upload = new Upload();
                $image = $upload->image($_FILES[$input], $field . "-" . time(), $width);
                if (!$image) { echo json_encode(["message" => $upload->message()->render()]); return; }
                $settings->{$field} = $image;
            }
            if (!$settings->save()) { echo json_encode(["message" => $settings->message()->render()]); return; }
            \Source\Support\AppLogger::log('info', 'Configurações do site atualizadas', ['event_type' => 'settings_updated', 'users_id' => $user->id, 'changed_fields' => array_values(array_intersect($fields, array_keys($data))), 'status' => 'resolved'], 'settings');
            echo json_encode(["reload" => true]);
            return;
        }
        $pdo = Connect::getInstance();
        $themeDirectories = $this->templateDirectories(dirname(__DIR__, 3) . "/container/web");
        $supportThemes = array_values(array_filter($themeDirectories, fn($name) => str_contains($name, "support")));
        echo $this->view->render("components/settings/home", $this->viewData("Configurações", "settings", $user, [
            "settings" => $settings,
            "versions" => $pdo->query("SELECT v.*,CONCAT(u.first_name,' ',u.last_name) author_name FROM movesos_versions v LEFT JOIN users u ON u.id=v.created_by ORDER BY v.id DESC LIMIT 20")->fetchAll(),
            "siteThemes" => array_values(array_diff($themeDirectories, $supportThemes)),
            "supportThemes" => $supportThemes,
            "appThemes" => $this->templateDirectories(dirname(__DIR__, 3) . "/container/apps/residents"),
            "erpThemes" => $this->templateDirectories(dirname(__DIR__, 3) . "/container/apps/erp")
            ,"languages" => ["pt_BR" => "Português (Brasil)", "pt_PT" => "Português (Portugal)", "en_US" => "English (United States)", "es_ES" => "Español", "fr_FR" => "Français"],
            "timezones" => \DateTimeZone::listIdentifiers()
        ]));
    }

    public function error(array $data): void
    {
        $code = (int)($data["errcode"] ?? 404);
        if (!in_array($code, [403, 404, 500], true)) $code = 404;
        http_response_code($code);
        $content = [
            403 => ["title" => "Acesso não autorizado", "message" => "Seu perfil não possui permissão para abrir esta área."],
            404 => ["title" => "Página não encontrada", "message" => "O endereço pode estar incorreto ou o conteúdo foi removido."],
            500 => ["title" => "Falha interna do sistema", "message" => "O incidente foi registrado. Tente novamente em alguns instantes."]
        ][$code];
        echo $this->view->render("components/error/error", ["code" => $code, "errorTitle" => $content["title"], "errorMessage" => $content["message"]]);
    }

    private function guard(?string $permission = "studio.access"): User
    {
        $user = Auth::user();
        if (!$user || !AccessControl::can("studio.access", $user)) {
            Auth::logout();
            redirect($this->isHelpDeskRequest() ? "/helpdesk/login" : "/studio/login");
        }
        if ($permission && !AccessControl::can($permission, $user)) {
            redirect(($this->isHelpDeskRequest() ? "/helpdesk" : "/studio") . "/ops/403");
        }
        return $user;
    }

    private function guardDeveloper(): User
    {
        // A permissão é a fonte única de autorização. Antes, o menu permitia
        // `logs.view`, mas o controller ainda bloqueava administradores que não
        // usavam o perfil interno "developer", fazendo o Log parecer quebrado.
        return $this->guard('logs.view');
    }

    private function safeMediaSelection(string $storedPath): ?string
    {
        $storedPath = ltrim(str_replace(["\\", "\0"], ["/", ""], trim($storedPath)), "/");
        if ($storedPath === "") return null;
        $root = dirname(__DIR__, 3);
        $base = realpath($root . "/" . CONF_UPLOAD_DIR . "/" . CONF_UPLOAD_IMAGE_DIR);
        $candidate = realpath($root . "/" . CONF_UPLOAD_DIR . "/" . $storedPath);
        if (!$base || !$candidate || !is_file($candidate) || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) return null;
        if (!in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), ["jpg","jpeg","png","webp","gif"], true)) return null;
        return preg_replace("~^" . preg_quote(CONF_UPLOAD_DIR . "/", "~") . "~", "", str_replace($root . "/", "", $candidate));
    }

    private function safeSvgUpload(array $file): ?string
    {
        $tmp = (string)($file["tmp_name"] ?? "");
        if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || filesize($tmp) > 524288) return null;
        $svg = file_get_contents($tmp);
        if (!$svg || !preg_match("~<svg\\b~i", $svg)) return null;
        if (preg_match("~<(script|foreignObject|iframe|object|embed)\\b|\\son[a-z]+\\s*=|javascript:|data\\s*:\\s*text/html~i", $svg)) return null;
        $folder = CONF_UPLOAD_IMAGE_DIR . "/" . date("Y/m");
        $absolute = dirname(__DIR__, 3) . "/" . CONF_UPLOAD_DIR . "/" . $folder;
        if (!is_dir($absolute) && !mkdir($absolute, 0755, true) && !is_dir($absolute)) return null;
        $name = "logo-svg-" . date("YmdHis") . "-" . bin2hex(random_bytes(3)) . ".svg";
        return move_uploaded_file($tmp, $absolute . "/" . $name) ? $folder . "/" . $name : null;
    }

    private function syncUserAccess(int $userId, int $roleId, array $overrides, int $assignedBy): void
    {
        $pdo = Connect::getInstance();
        $pdo->beginTransaction();
        try {
            $roleSlugStmt = $pdo->prepare("SELECT slug FROM access_roles WHERE id=:id");
            $roleSlugStmt->execute(["id" => $roleId]);
            $roleSlug = (string)$roleSlugStmt->fetchColumn();
            $level = in_array($roleSlug, ["developer", "super_admin"], true) ? 10 : ($roleSlug === "client_admin" ? 5 : (in_array($roleSlug, ["manager", "operator"], true) ? 2 : 1));
            $stmt = $pdo->prepare("INSERT INTO access_user_roles (user_id,role_id,assigned_by) VALUES (:user,:role,:by) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),assigned_by=VALUES(assigned_by)");
            $stmt->execute(["user" => $userId, "role" => $roleId, "by" => $assignedBy]);
            $pdo->prepare("UPDATE users SET level=:level WHERE id=:user")->execute(["level" => $level, "user" => $userId]);
            $pdo->prepare("DELETE FROM access_user_overrides WHERE user_id=:user")->execute(["user" => $userId]);
            $insert = $pdo->prepare("INSERT INTO access_user_overrides (user_id,permission_id,effect,assigned_by) SELECT :user,id,:effect,:by FROM access_permissions WHERE slug=:slug");
            foreach ($overrides as $slug => $effect) {
                if (!in_array($effect, ["allow", "deny"], true)) continue;
                $insert->execute(["user" => $userId, "effect" => $effect, "by" => $assignedBy, "slug" => $slug]);
            }
            $pdo->commit();
            AccessControl::clear($userId);
            \Source\Support\Audit::record("update", "access_control", $userId, [], ["role_id" => $roleId, "permission_overrides" => $overrides]);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    private function viewData(string $title, string $app, User $user, array $data = []): array
    {
        $this->ensureVersionStorage();
        $currentVersion = VERSION_STUDIO;
        try {
            $foundVersion = Connect::getInstance()->query("SELECT version FROM movesos_versions WHERE product='studio' AND status='current' ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($foundVersion) $currentVersion = (string)$foundVersion;
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, "versioning", ["event_type" => "current_version_lookup_failed"]);
        }
        return array_merge(["head" => $this->seo->render(
            $title . " - MovesOS",
            CONF_SITE_DESC,
            url($this->isHelpDeskRequest() ? "/helpdesk" : "/studio"),
            themeStudio("/assets/images/favicon.png", "default")
        ), "title" => $title, "app" => $app, "user" => $user, "currentVersion" => $currentVersion, "adminBase" => $this->environmentBase()], $data);
    }

    private function isHelpDeskRequest(): bool
    {
        $path = (string)(parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?? "");
        return (bool)preg_match("~/helpdesk(?:/|$)~", $path);
    }

    private function environmentBase(): string
    {
        return $this->isHelpDeskRequest() ? "/helpdesk" : "/studio";
    }

    private function ensureVersionStorage(): void
    {
        static $ready = false;
        if ($ready) return;
        try {
            $pdo = Connect::getInstance();
            try {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM movesos_versions")->fetchColumn();
            } catch (\Throwable) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS movesos_versions (id INT UNSIGNED NOT NULL AUTO_INCREMENT,product ENUM('web','app','studio','erp','support') NOT NULL DEFAULT 'studio',version VARCHAR(30) NOT NULL,name VARCHAR(120) DEFAULT NULL,notes TEXT DEFAULT NULL,status ENUM('current','archived') NOT NULL DEFAULT 'current',created_by INT UNSIGNED DEFAULT NULL,published_at DATETIME NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_movesos_product_version(product,version),KEY idx_movesos_version_status(product,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $count = 0;
            }
            $hasProduct = (bool)$pdo->query("SHOW COLUMNS FROM movesos_versions LIKE 'product'")->fetch();
            if (!$hasProduct) {
                $pdo->exec("ALTER TABLE movesos_versions ADD product ENUM('web','app','studio','erp','support') NOT NULL DEFAULT 'studio' AFTER id");
                $oldIndex = $pdo->query("SHOW INDEX FROM movesos_versions WHERE Key_name='uq_movesos_version'")->fetch();
                if ($oldIndex) $pdo->exec("ALTER TABLE movesos_versions DROP INDEX uq_movesos_version");
                $pdo->exec("ALTER TABLE movesos_versions ADD UNIQUE KEY uq_movesos_product_version(product,version), ADD KEY idx_movesos_product_status(product,status)");
            }
            $productColumn = $pdo->query("SHOW COLUMNS FROM movesos_versions LIKE 'product'")->fetch();
            if ($productColumn && !str_contains((string)$productColumn->Type, "'support'")) {
                $pdo->exec("ALTER TABLE movesos_versions MODIFY product ENUM('web','app','studio','erp','support') NOT NULL DEFAULT 'studio'");
            }
            $seed = $pdo->prepare("INSERT INTO movesos_versions(product,version,name,notes,status,created_by,published_at) SELECT :product,:version,'Fundação','Versão inicial deste produto no MovesOS.','current',NULL,NOW() WHERE NOT EXISTS (SELECT 1 FROM movesos_versions WHERE product=:product_check)");
            foreach (["web" => VERSION_SITE, "app" => VERSION_APP, "studio" => VERSION_STUDIO, "erp" => VERSION_ERP, "support" => VERSION_SUPPORT] as $product => $version) {
                $seed->execute(["product" => $product, "version" => $version, "product_check" => $product]);
            }
            $ready = true;
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, "versioning", ["event_type" => "version_storage_boot_failed"]);
        }
    }

    private function dispatchScheduledNotifications(): void
    {
        $this->cleanupOldNotifications();
        (new Communication())->dispatchScheduled(20);
    }

    private function deliverNotificationMessage(NotificationMessage $message): void
    {
        (new Communication())->deliver($message);
    }

    private function cleanupOldNotifications(): void
    {
        (new Notification())->delete("created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH) OR (expires_at IS NOT NULL AND expires_at <= NOW())", null);
    }

    private function jsonMessage(string $text, string $type): void
    {
        echo json_encode(["message" => $this->message->{$type}($text)->render()]);
    }

    private function safeNotificationLink(string $link): ?string
    {
        if ($link === "") { return url("/studio/dash"); }
        if (str_starts_with($link, "/") && !str_starts_with($link, "//")) { return $link; }
        $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($link, PHP_URL_HOST));
        $systemHost = strtolower((string)parse_url(url(), PHP_URL_HOST));
        return in_array($scheme, ["http", "https"], true) && $host !== "" && hash_equals($systemHost, $host) ? $link : null;
    }

    private function templateDirectories(string $base): array
    {
        if (!is_dir($base)) return [];
        $templates = [];
        foreach (new \DirectoryIterator($base) as $directory) {
            if ($directory->isDot() || !$directory->isDir()) continue;
            if (is_file($directory->getPathname() . "/default.php")) $templates[] = $directory->getFilename();
        }
        sort($templates, SORT_NATURAL | SORT_FLAG_CASE);
        return $templates;
    }
}
