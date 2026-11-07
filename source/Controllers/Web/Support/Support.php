<?php

namespace Source\Controllers\Web\Support;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;
use Source\Support\Upload;

class Support extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('web', 'support') . "/");
    }

    public function home(): void
    {
        $categories = $this->categories();
        echo $this->view->render("home", ["head" => $this->seo->render("Suporte - " . CONF_SITE_NAME, "Tutoriais e respostas para usar o MovesOS.", url("/suporte"), theme("/assets/images/share.jpg")), "categories" => $categories, "popular" => (new SupportArticle())->findPublished()->order("views DESC, helpful_yes DESC")->limit(6)->fetch(true) ?: []]);
    }

    public function search(): void
    {
        $search = trim(strip_tags((string)($_GET["q"] ?? "")));
        $articles = [];
        if (mb_strlen($search) >= 2) {
            $like = urlencode("%{$search}%");
            $articles = (new SupportArticle())->findPublished("(title LIKE :q1 OR summary LIKE :q2 OR content LIKE :q3)", "q1={$like}&q2={$like}&q3={$like}")->order("views DESC, title")->limit(40)->fetch(true) ?: [];
        }
        echo $this->view->render("search", ["head" => $this->seo->render("Busca no Suporte", "Resultados da busca de suporte.", url("/suporte/buscar?q=" . urlencode($search)), theme("/assets/images/share.jpg")), "search" => $search, "articles" => $articles, "categories" => $this->categories()]);
    }

    public function widget(): void
    {
        header("Content-Security-Policy: frame-ancestors *");
        echo $this->view->render('widget', ['siteName' => CONF_SITE_NAME, 'widgetToken' => $this->widgetToken()]);
    }

    public function widgetCreate(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->validWidgetRequest($data)) { echo json_encode(['success' => false, 'message' => 'Sessão expirada. Reabra o atendimento.']); return; }
        if (!empty($data['website'])) { echo json_encode(['success' => true]); return; }
        if (request_limit('public_helpdesk_widget', 5, 3600)) { echo json_encode(['success' => false, 'message' => 'Limite de solicitações atingido. Aguarde antes de tentar novamente.']); return; }

        $name = mb_substr(trim(strip_tags((string)($data['name'] ?? ''))), 0, 160);
        $email = mb_strtolower(mb_substr(trim((string)($data['email'] ?? '')), 0, 190));
        $subject = mb_substr(trim(strip_tags((string)($data['subject'] ?? ''))), 0, 180);
        $message = trim(strip_tags((string)($data['message'] ?? '')));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($subject) < 4 || mb_strlen($message) < 10) {
            echo json_encode(['success' => false, 'message' => 'Preencha nome, e-mail, assunto e uma mensagem com pelo menos 10 caracteres.']); return;
        }

        $pdo = Connect::getInstance();
        $protocol = 'CH' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $token = hash('sha256', random_bytes(32));
        $dueAt = date('Y-m-d H:i:s', strtotime('+72 hours'));
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO studio_support_tickets(protocol,subject,message,area,priority,requester_id,requester_name,requester_email,access_token,assigned_to,created_by,due_at) VALUES(:protocol,:subject,:message,'general','medium',NULL,:name,:email,:token,NULL,NULL,:due)");
            $stmt->execute(['protocol' => $protocol, 'subject' => $subject, 'message' => $message, 'name' => $name, 'email' => $email, 'token' => $token, 'due' => $dueAt]);
            $ticketId = (int)$pdo->lastInsertId();
            $this->saveWidgetAttachments($pdo, $ticketId);
            $pdo->commit();
            \Source\Support\AppLogger::log('info', 'Chamado aberto pelo widget', ['event_type' => 'public_ticket_created', 'ticket_id' => $ticketId, 'protocol' => $protocol], 'support');
            echo json_encode(['success' => true, 'protocol' => $protocol, 'token' => $token, 'message' => 'Chamado enviado com sucesso.']);
        } catch (\InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            \Source\Support\AppLogger::exception($exception, 'support', ['event_type' => 'public_ticket_create_failed']);
            echo json_encode(['success' => false, 'message' => 'Não foi possível abrir o chamado agora. Tente novamente.']);
        }
    }

    public function widgetLookup(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->validWidgetRequest($data)) { echo json_encode(['success' => false, 'message' => 'Sessão expirada. Reabra o atendimento.']); return; }
        if (request_limit('public_helpdesk_lookup', 20, 900)) { echo json_encode(['success' => false, 'message' => 'Muitas consultas. Aguarde alguns minutos.']); return; }
        $protocol = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($data['protocol'] ?? '')));
        $email = mb_strtolower(trim((string)($data['email'] ?? '')));
        $token = preg_replace('/[^a-f0-9]/i', '', (string)($data['token'] ?? ''));
        if (!preg_match('/^CH\d{6}[A-F0-9]{4}$/', $protocol) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => 'Informe o protocolo e o e-mail utilizados na abertura.']); return; }
        $pdo = Connect::getInstance();
        $sql = 'SELECT id,protocol,subject,status,created_at,updated_at FROM studio_support_tickets WHERE protocol=:protocol AND requester_email=:email';
        $params = ['protocol' => $protocol, 'email' => $email];
        if ($token !== '') { $sql .= ' AND access_token=:token'; $params['token'] = $token; }
        $find = $pdo->prepare($sql . ' LIMIT 1'); $find->execute($params); $ticket = $find->fetch();
        if (!$ticket) { echo json_encode(['success' => false, 'message' => 'Chamado não encontrado para esses dados.']); return; }
        $messages = $pdo->prepare("SELECT m.message,m.created_at,CONCAT(u.first_name,' ',u.last_name) author FROM studio_support_ticket_messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:ticket AND m.is_internal=0 ORDER BY m.created_at");
        $messages->execute(['ticket' => $ticket->id]);
        echo json_encode(['success' => true, 'ticket' => $ticket, 'messages' => $messages->fetchAll() ?: []]);
    }

    private function saveWidgetAttachments(\PDO $pdo, int $ticketId): void
    {
        $files = $_FILES['attachments'] ?? null;
        if (!$files || empty($files['tmp_name']) || !is_array($files['tmp_name'])) return;
        foreach (array_slice($files['tmp_name'], 0, 3, true) as $index => $temporary) {
            if (!$temporary) continue;
            $file = ['name' => $files['name'][$index] ?? 'arquivo', 'type' => $files['type'][$index] ?? '', 'tmp_name' => $temporary, 'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$index] ?? 0];
            if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('Não foi possível receber um dos anexos. Selecione o arquivo novamente.');
            if ((int)$file['size'] > 5242880) throw new \InvalidArgumentException('Cada anexo deve ter no máximo 5 MB.');
            $detectedMime = is_file($temporary) ? strtolower((string)(new \finfo(FILEINFO_MIME_TYPE))->file($temporary)) : '';
            $upload = new Upload();
            $uploadName = 'public-ticket-' . $ticketId . '-' . bin2hex(random_bytes(4));
            $path = str_starts_with($detectedMime, 'image/')
                ? $upload->image($file, $uploadName)
                : $upload->file($file, $uploadName);
            if (!$path) throw new \InvalidArgumentException('Formato de anexo não permitido. Envie PDF, imagem, documento, planilha ou arquivo TXT.');
            $stmt = $pdo->prepare('INSERT INTO studio_support_ticket_attachments(ticket_id,message_id,user_id,file_path,original_name,mime_type,file_size) VALUES(?,NULL,NULL,?,?,?,?)');
            $stmt->execute([$ticketId, $path, mb_substr(basename((string)$file['name']), 0, 255), $detectedMime ?: null, (int)$file['size']]);
        }
    }

    private function widgetToken(): string
    {
        $timestamp = time();
        return $timestamp . '.' . hash_hmac('sha256', $timestamp . '|helpdesk-widget', $this->widgetSecret());
    }

    private function validWidgetRequest(array $data): bool
    {
        if (csrf_verify($data)) return true;
        $parts = explode('.', (string)($data['widget_token'] ?? ''), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) return false;
        $timestamp = (int)$parts[0];
        if ($timestamp > time() + 60 || $timestamp < time() - 7200) return false;
        $expected = hash_hmac('sha256', $timestamp . '|helpdesk-widget', $this->widgetSecret());
        return hash_equals($expected, $parts[1]);
    }

    private function widgetSecret(): string
    {
        return hash('sha256', CONF_DB_NAME . '|' . CONF_DB_PASS . '|' . CONF_APP_URL);
    }

    public function category(array $data): void
    {
        $section = str_slug($data["section"] ?? $data["category"] ?? "");
        $category = (new SupportCategory())->findByUri($section);
        if (!$category || $category->status !== "active") {
            \Source\Support\AppLogger::log('warning', 'Categoria pública de suporte não localizada', [
                'event_type' => 'support_category_not_found',
                'route_data' => $data,
                'normalized_category' => $section,
            ], 'support');
            redirect("/suporte");
            return;
        }
        echo $this->view->render("category", ["head" => $this->seo->render($category->title . " - Suporte", $category->description, url("/suporte/{$category->uri}"), theme("/assets/images/share.jpg")), "category" => $category, "articles" => $category->articles(true)->order("title")->fetch(true) ?: [], "categories" => $this->categories()]);
    }

    public function article(array $data): void
    {
        $section = str_slug($data["section"] ?? $data["category"] ?? "");
        $category = (new SupportCategory())->findByUri($section);
        $article = (new SupportArticle())->findByUri(str_slug($data["article"] ?? ""), true);
        if (!$category || !$article || (int)$article->category_id !== (int)$category->id) { redirect("/suporte"); return; }
        Connect::getInstance()->prepare("UPDATE support_articles SET views=views+1 WHERE id=:id")->execute(["id" => $article->id]);
        $related = (new SupportArticle())->findPublished("category_id=:category AND id!=:id", "category={$category->id}&id={$article->id}")->order("views DESC,title")->limit(5)->fetch(true) ?: [];
        echo $this->view->render("article", ["head" => $this->seo->render($article->title . " - Suporte", $article->summary, url("/suporte/{$category->uri}/{$article->uri}"), $article->cover ? image($article->cover, 1200, 628) : theme("/assets/images/share.jpg")), "article" => $article, "category" => $category, "related" => $related, "categories" => $this->categories()]);
    }

    public function vote(array $data): void
    {
        header("Content-Type: application/json; charset=utf-8");
        if (!csrf_verify($data)) { echo json_encode(["message" => "Atualize a página e tente novamente."]); return; }
        $articleId = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $vote = in_array($data["vote"] ?? "", ["yes", "no"], true) ? $data["vote"] : null;
        if (!$articleId || !$vote || !(new SupportArticle())->findById($articleId)) { echo json_encode(["message" => "Avaliação inválida."]); return; }
        $hash = hash("sha256", $articleId . "|" . ($_SERVER["REMOTE_ADDR"] ?? "") . "|" . ($_SERVER["HTTP_USER_AGENT"] ?? ""));
        $pdo = Connect::getInstance();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare("SELECT * FROM support_article_votes WHERE article_id=:article AND visitor_hash=:hash FOR UPDATE");
            $find->execute(["article" => $articleId, "hash" => $hash]);
            $old = $find->fetch();
            if (!$old) {
                $pdo->prepare("INSERT INTO support_article_votes (article_id,vote,visitor_hash) VALUES (:article,:vote,:hash)")->execute(["article" => $articleId, "vote" => $vote, "hash" => $hash]);
                $pdo->prepare("UPDATE support_articles SET helpful_{$vote}=helpful_{$vote}+1 WHERE id=:id")->execute(["id" => $articleId]);
            } elseif ($old->vote !== $vote) {
                $pdo->prepare("UPDATE support_article_votes SET vote=:vote WHERE id=:id")->execute(["vote" => $vote, "id" => $old->id]);
                $pdo->prepare("UPDATE support_articles SET helpful_{$old->vote}=GREATEST(0,helpful_{$old->vote}-1),helpful_{$vote}=helpful_{$vote}+1 WHERE id=:id")->execute(["id" => $articleId]);
            }
            $pdo->commit(); echo json_encode(["success" => true, "message" => "Obrigado pela sua avaliação!"]);
        } catch (\Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); \Source\Support\AppLogger::exception($exception, 'support', ['event_type' => 'support_vote_failed', 'article_id' => $articleId]); echo json_encode(["message" => "Não foi possível registrar sua avaliação."]); }
    }

    private function categories(): array
    {
        return (new SupportCategory())->find("status=:status", "status=active")->order("position,title")->fetch(true) ?: [];
    }
}
