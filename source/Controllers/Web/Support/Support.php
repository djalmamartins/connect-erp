<?php

namespace Source\Controllers\Web\Support;

use Source\Core\Connect;
use Source\Core\Controller;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;
use Source\Services\Help\KnowledgeBase;
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
        $counts = [];
        foreach (Connect::getInstance()->query("SELECT category_id, COUNT(*) total FROM support_articles WHERE status = 'published' AND published_at <= NOW() GROUP BY category_id")->fetchAll() ?: [] as $row) {
            $counts[(int)$row->category_id] = (int)$row->total;
        }
        echo $this->view->render("pages/home", [
            "head" => $this->seo->render("Central de Ajuda - " . CONF_SITE_NAME, "Tutoriais com as telas do sistema, passo a passo, e atendimento da Connect.", url("/suporte"), theme("/assets/images/share.jpg")),
            "categories" => $categories,
            "counts" => $counts,
            "popular" => (new SupportArticle())->findPublished()->order("views DESC, helpful_yes DESC, id")->limit(6)->fetch(true) ?: [],
            "formToken" => $this->widgetToken(),
            "sent" => isset($_GET["enviado"]) ? preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$_GET["enviado"])) : null,
            "showcase" => is_file(dirname(__DIR__, 4) . KnowledgeBase::shotUrl('dashboard')) ? url(KnowledgeBase::shotUrl('dashboard')) : null,
        ]);
    }

    /**
     * "Deixe a sua mensagem" (ADR 0141): abre o chamado no Help Desk pelo serviço único (ADR 0034),
     * origem site, com CSRF ou o token assinado do formulário, armadilha para robôs e limite por IP.
     * Responde JSON para o envio por fetch e redireciona com o protocolo sem JavaScript.
     */
    public function message(array $data): void
    {
        $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $reply = function (bool $ok, string $text, ?string $protocol = null, ?string $field = null) use ($wantsJson): void {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $ok, 'message' => $text, 'protocol' => $protocol, 'field' => $field]);
                return;
            }
            redirect('/suporte' . ($ok && $protocol ? '?enviado=' . $protocol : '') . '#mensagem');
        };
        if (!$this->validWidgetRequest($data)) { $reply(false, 'A página expirou. Atualize e envie de novo.'); return; }
        if (!empty($data['website'])) { $reply(true, 'Mensagem enviada.'); return; }
        if (request_limit('public_support_message', 5, 3600)) { $reply(false, 'Muitas mensagens em pouco tempo. Aguarde um pouco e tente de novo.'); return; }

        $name = mb_substr(trim(strip_tags((string)($data['name'] ?? ''))), 0, 160);
        $email = mb_strtolower(mb_substr(trim((string)($data['email'] ?? '')), 0, 190));
        $phone = mb_substr(preg_replace('/[^0-9+() -]/', '', (string)($data['phone'] ?? '')) ?? '', 0, 30);
        $message = trim(strip_tags((string)($data['message'] ?? '')));
        if (mb_strlen($name) < 2) { $reply(false, 'Informe o seu nome.', null, 'name'); return; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $reply(false, 'Informe um e-mail válido para a resposta.', null, 'email'); return; }
        if (!empty($data['whatsapp']) && strlen(preg_replace('/\D+/', '', $phone) ?? '') < 10) { $reply(false, 'Para responder pelo WhatsApp, informe o telefone com DDD.', null, 'phone'); return; }
        if (!empty($data['whatsapp'])) {
            $message .= "\n\n[Aceita resposta por WhatsApp no telefone informado.]";
        }
        try {
            $opened = (new \Source\Services\Support\TicketService())->open([
                'subject' => (string)($data['subject'] ?? ''), 'message' => $message, 'category' => 'Central de Ajuda',
                'requester_name' => $name, 'requester_email' => $email, 'requester_phone' => $phone,
            ], 'site');
            \Source\Support\AppLogger::log('info', 'Mensagem da Central de Ajuda virou chamado', ['event_type' => 'support_message_ticket', 'ticket_id' => $opened['id'], 'protocol' => $opened['protocol']], 'support');
            $reply(true, 'Recebemos a sua mensagem. O protocolo é ' . $opened['protocol'] . ' e a resposta chega no seu e-mail.', $opened['protocol']);
        } catch (\Source\Services\Support\TicketError $exception) {
            $reply(false, $exception->getMessage(), null, $exception->field ?? null);
        } catch (\Throwable $exception) {
            \Source\Support\AppLogger::exception($exception, 'support', ['event_type' => 'support_message_failed']);
            $reply(false, 'Não foi possível enviar agora. Tente de novo em alguns minutos.');
        }
    }

    /**
     * "Ajuda desta página" no ERP: leva do código da ficha (AP-xxx) ao tutorial publicado.
     * O mapa vem dos arquivos da base (sem consulta); código sem artigo cai na página inicial.
     */
    public function screen(array $data): void
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($data['code'] ?? '')) ?? '');
        $article = $code !== '' ? ((new KnowledgeBase())->byCode()[$code] ?? null) : null;
        if (!$article || $article['draft']) {
            redirect('/suporte');
            return;
        }
        redirect('/suporte/' . $article['folder'] . '/' . $article['slug']);
    }

    public function search(): void
    {
        $search = mb_substr(trim(strip_tags((string)($_GET["q"] ?? ""))), 0, 120);
        $articles = [];
        $terms = array_slice(array_values(array_filter(preg_split('/\s+/u', $search) ?: [], static fn ($t) => mb_strlen($t) >= 2)), 0, 6);
        if ($terms) {
            // Cada palavra precisa aparecer no título, no resumo ou no texto; título vem primeiro.
            $where = [];
            $params = [];
            foreach ($terms as $i => $term) {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $where[] = "(title LIKE :t{$i} OR summary LIKE :s{$i} OR content LIKE :c{$i})";
                $params["t{$i}"] = $params["s{$i}"] = $params["c{$i}"] = $like;
            }
            $params['rank'] = '%' . addcslashes($terms[0], '%_\\') . '%';
            $statement = Connect::getInstance()->prepare("SELECT * FROM support_articles WHERE status = 'published' AND published_at <= NOW() AND " . implode(' AND ', $where) . " ORDER BY (title LIKE :rank) DESC, views DESC, title LIMIT 40");
            $statement->execute($params);
            $articles = $statement->fetchAll(\PDO::FETCH_CLASS, SupportArticle::class) ?: [];
        }
        echo $this->view->render("pages/search", ["head" => $this->seo->render("Busca na Central de Ajuda", "Resultados da busca na Central de Ajuda.", url("/suporte/buscar?q=" . urlencode($search)), theme("/assets/images/share.jpg")), "search" => $search, "articles" => $articles, "categories" => $this->categories()]);
    }

    public function widget(): void
    {
        header("Content-Security-Policy: frame-ancestors *");
        echo $this->view->render('pages/widget', ['siteName' => CONF_SITE_NAME, 'widgetToken' => $this->widgetToken()]);
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

        // Abertura pelo serviço único (ADR 0034): o chamado do site cai na fila do Help Desk.
        try {
            $opened = (new \Source\Services\Support\TicketService())->open(
                ['subject' => $subject, 'message' => $message, 'requester_name' => $name, 'requester_email' => $email],
                'site',
                null,
                fn (\PDO $pdo, int $ticketId) => $this->saveWidgetAttachments($pdo, $ticketId)
            );
            \Source\Support\AppLogger::log('info', 'Chamado aberto pelo widget', ['event_type' => 'public_ticket_created', 'ticket_id' => $opened['id'], 'protocol' => $opened['protocol']], 'support');
            echo json_encode(['success' => true, 'protocol' => $opened['protocol'], 'token' => $opened['access_token'], 'message' => 'Chamado enviado com sucesso.']);
        } catch (\Source\Services\Support\TicketError $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        } catch (\InvalidArgumentException $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
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
        echo $this->view->render("pages/category", ["head" => $this->seo->render($category->title . " - Suporte", $category->description, url("/suporte/{$category->uri}"), theme("/assets/images/share.jpg")), "category" => $category, "articles" => $category->articles(true)->order("title")->fetch(true) ?: [], "categories" => $this->categories()]);
    }

    public function article(array $data): void
    {
        $section = str_slug($data["section"] ?? $data["category"] ?? "");
        $category = (new SupportCategory())->findByUri($section);
        $article = (new SupportArticle())->findByUri(str_slug($data["article"] ?? ""), true);
        if (!$category || !$article || (int)$article->category_id !== (int)$category->id) { redirect("/suporte"); return; }
        Connect::getInstance()->prepare("UPDATE support_articles SET views=views+1 WHERE id=:id")->execute(["id" => $article->id]);
        $meta = self::articleMeta($article);
        $related = $meta['related'];
        if (!$related) {
            foreach ((new SupportArticle())->findPublished("category_id=:category AND id!=:id", "category={$category->id}&id={$article->id}")->order("views DESC,title")->limit(4)->fetch(true) ?: [] as $item) {
                $related[] = ['title' => $item->title, 'url' => '/suporte/' . $category->uri . '/' . $item->uri];
            }
        }
        $cover = $meta['cover'] ? url($meta['cover']) : ($article->cover ? image($article->cover, 1200, 628) : null);
        echo $this->view->render("pages/article", [
            "head" => $this->seo->render($article->title . " - Central de Ajuda", $article->summary, url("/suporte/{$category->uri}/{$article->uri}"), $cover ?: ($meta['image'] ? url($meta['image']) : theme("/assets/images/share.jpg"))),
            "article" => $article, "category" => $category, "related" => $related, "meta" => $meta, "cover" => $cover,
            "categories" => $this->categories(),
        ]);
    }

    /**
     * Metadados do artigo: os da base de conhecimento (kb_meta, ADR 0141) ou, numa matéria escrita
     * no Help Desk, o índice tirado dos títulos h2 do próprio texto.
     *
     * @return array{icon:string,route:string,menu:string,situation:string,toc:list<array{id:string,title:string}>,related:list<array{title:string,url:string}>,cover:?string,image:?string}
     */
    public static function articleMeta(object $article): array
    {
        $meta = json_decode((string)($article->kb_meta ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $toc = $meta['toc'] ?? [];
        if (!$toc && preg_match_all('/<h2[^>]*id="([^"]+)"[^>]*>(.*?)<\/h2>/is', (string)$article->content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toc[] = ['id' => $match[1], 'title' => html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8')];
            }
        }
        return [
            'icon' => preg_match('/^[a-z0-9-]+$/', (string)($meta['icon'] ?? '')) ? $meta['icon'] : 'document-text-outline',
            'route' => (string)($meta['route'] ?? ''),
            'menu' => (string)($meta['menu'] ?? ''),
            'situation' => (string)($meta['situation'] ?? ''),
            'toc' => $toc,
            'related' => array_values(array_filter($meta['related'] ?? [], static fn ($r) => is_array($r) && isset($r['title'], $r['url']) && str_starts_with((string)$r['url'], '/suporte/'))),
            'cover' => isset($meta['cover']) && is_string($meta['cover']) && str_starts_with($meta['cover'], '/container/web/support/') ? $meta['cover'] : null,
            'image' => isset($meta['image']) && is_string($meta['image']) && str_starts_with($meta['image'], '/container/web/support/') ? $meta['image'] : null,
        ];
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
