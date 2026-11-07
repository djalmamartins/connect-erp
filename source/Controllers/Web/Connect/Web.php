<?php

namespace Source\Controllers\Web\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Post\Category;
use Source\Models\Post\Page;
use Source\Models\Post\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Brief\AppBrief;
use Source\Models\Slide\AppSlides;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\Pager;
use Source\Support\Proposal\ProposalService;

/**
 * Web Controller
 * @package Source\Controllers\Web\Connect
 */
class Web extends Controller
{
    /**
     * Web constructor.
     */
    public function __construct()
    {
        parent::__construct(moves_container_path('web', CONF_VIEW_THEME) . "/");

        (new Access())->report();
        (new Online())->report();
    }

    /**
     * SITE HOME
     */
    public function home(): void
    {

        $head = $this->seo->render(
            CONF_SITE_NAME . " - " . CONF_SITE_TITLE,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/home", [
            "head" => $head,
            "briefs" => (new AppBrief())
                ->find("status = :status", "status=published")
                ->order("id DESC")
                ->limit(10)
                ->fetch(true) ?? [],
            "articles" => (new Post())
                ->findPost()
                ->order("post_at DESC")
                ->limit(4)
                ->fetch(true) ?? [],
        ]);
    }

    public function sales(): void
    {

        $head = $this->seo->render(
            "Porque escolher a " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/proposta"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/sales", [
            "head" => $head,
            "briefs" => (new AppBrief())
                ->find("status = :status", "status=published")
                ->order("id DESC")
                ->limit(10)
                ->fetch(true) ?? [],
        ]);
    }

    public function proposalSubmit(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_verify($data)) {
            echo json_encode(['message' => $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->render()]);
            return;
        }
        if (request_limit('public_proposal', 4, 3600)) {
            echo json_encode(['message' => $this->message->warning('Você já enviou algumas solicitações. Aguarde antes de tentar novamente.')->render()]);
            return;
        }
        $result = (new ProposalService())->submit($data);
        if (!$result['success']) {
            echo json_encode(['message' => $this->message->warning($result['message'])->render()]);
            return;
        }
        echo json_encode([
            'message' => $this->message->success($result['message'] . ' Protocolo: ' . ($result['protocol'] ?? ''))->render(),
            'proposal_success' => true,
            'protocol' => $result['protocol'] ?? null,
        ]);
    }

    public function supportHome(): void
    {
        $categories = (new SupportCategory())->find("status = :status", "status=active")->order("position, title")->fetch(true) ?: [];
        echo $this->view->render("support/home", ["head" => $this->seo->render("Central de Ajuda - " . CONF_SITE_NAME, "Tutoriais e respostas para usar o sistema.", url("/suporte"), theme("/assets/images/share.jpg")), "categories" => $categories, "popular" => (new SupportArticle())->findPublished()->order("views DESC, helpful_yes DESC")->limit(6)->fetch(true) ?: []]);
    }

    public function supportSearch(): void
    {
        $search = trim(strip_tags((string)($_GET["q"] ?? "")));
        $articles = [];
        if (mb_strlen($search) >= 2) {
            $like = urlencode("%{$search}%");
            $articles = (new SupportArticle())->findPublished("(title LIKE :q1 OR summary LIKE :q2 OR content LIKE :q3)", "q1={$like}&q2={$like}&q3={$like}")->order("views DESC, title")->limit(40)->fetch(true) ?: [];
        }
        echo $this->view->render("support/search", ["head" => $this->seo->render("Busca na Central de Ajuda", "Resultados da busca de suporte.", url("/suporte/buscar?q=" . urlencode($search)), theme("/assets/images/share.jpg")), "search" => $search, "articles" => $articles, "categories" => (new SupportCategory())->find("status = :s", "s=active")->order("position")->fetch(true) ?: []]);
    }

    public function supportCategory(array $data): void
    {
        $category = (new SupportCategory())->findByUri(str_slug($data["category"] ?? ""));
        if (!$category || $category->status !== "active") { redirect("/suporte"); return; }
        echo $this->view->render("support/category", ["head" => $this->seo->render($category->title . " - Central de Ajuda", $category->description, url("/suporte/{$category->uri}"), theme("/assets/images/share.jpg")), "category" => $category, "articles" => $category->articles(true)->order("title")->fetch(true) ?: [], "categories" => (new SupportCategory())->find("status = :s", "s=active")->order("position")->fetch(true) ?: []]);
    }

    public function supportArticle(array $data): void
    {
        $category = (new SupportCategory())->findByUri(str_slug($data["category"] ?? ""));
        $article = (new SupportArticle())->findByUri(str_slug($data["article"] ?? ""), true);
        if (!$category || !$article || (int)$article->category_id !== (int)$category->id) { redirect("/suporte"); return; }
        Connect::getInstance()->prepare("UPDATE support_articles SET views = views + 1 WHERE id = :id")->execute(["id" => $article->id]);
        $related = (new SupportArticle())->findPublished("category_id = :category AND id != :id", "category={$category->id}&id={$article->id}")->order("views DESC, title")->limit(5)->fetch(true) ?: [];
        echo $this->view->render("support/article", ["head" => $this->seo->render($article->title . " - Ajuda", $article->summary, url("/suporte/{$category->uri}/{$article->uri}"), $article->cover ? image($article->cover, 1200, 628) : theme("/assets/images/share.jpg")), "article" => $article, "category" => $category, "related" => $related, "categories" => (new SupportCategory())->find("status = :s", "s=active")->order("position")->fetch(true) ?: []]);
    }

    public function supportVote(array $data): void
    {
        header("Content-Type: application/json; charset=utf-8");
        if (!csrf_verify($data)) { echo json_encode(["message" => "Atualize a página e tente novamente."]); return; }
        $articleId = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT);
        $vote = in_array($data["vote"] ?? "", ["yes", "no"], true) ? $data["vote"] : null;
        if (!$articleId || !$vote || !(new SupportArticle())->findById($articleId)) { echo json_encode(["message" => "Avaliação inválida."]); return; }
        $hash = hash("sha256", ($articleId . "|" . ($_SERVER["REMOTE_ADDR"] ?? "") . "|" . ($_SERVER["HTTP_USER_AGENT"] ?? "")));
        $pdo = Connect::getInstance();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare("SELECT * FROM support_article_votes WHERE article_id=:article AND visitor_hash=:hash FOR UPDATE");
            $find->execute(["article" => $articleId, "hash" => $hash]);
            $old = $find->fetch();
            if (!$old) {
                $pdo->prepare("INSERT INTO support_article_votes (article_id,vote,visitor_hash) VALUES (:article,:vote,:hash)")->execute(["article" => $articleId, "vote" => $vote, "hash" => $hash]);
                $pdo->prepare("UPDATE support_articles SET helpful_{$vote} = helpful_{$vote} + 1 WHERE id=:id")->execute(["id" => $articleId]);
            } elseif ($old->vote !== $vote) {
                $pdo->prepare("UPDATE support_article_votes SET vote=:vote WHERE id=:id")->execute(["vote" => $vote, "id" => $old->id]);
                $pdo->prepare("UPDATE support_articles SET helpful_{$old->vote}=GREATEST(0,helpful_{$old->vote}-1), helpful_{$vote}=helpful_{$vote}+1 WHERE id=:id")->execute(["id" => $articleId]);
            }
            $pdo->commit(); echo json_encode(["success" => true, "message" => "Obrigado pela sua avaliação!"]);
        } catch (\Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); \Source\Support\AppLogger::exception($exception, 'support', ['event_type' => 'support_vote_failed', 'article_id' => $id]); echo json_encode(["message" => "Não foi possível registrar sua avaliação."]); }
    }

    /**
     * SITE ABOUT
     */
    public function about(): void
    {
        $head = $this->seo->render(
            "Porque escolher a " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/sobre"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("about", [
            "head" => $head,
        ]);
    }

    public function benefits(): void
    {
        $head = $this->seo->render(
            "Vantagens " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/vantagens"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("benefits", [
            "head" => $head,
        ]);
    }

    public function services(): void
    {
        $head = $this->seo->render(
            "Onde Atuamos " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/onde-atuamos"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("services", [
            "head" => $head,
        ]);
    }

    public function solve(): void
    {
        $head = $this->seo->render(
            "Onde Atuamos " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/resolva-facil"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("solve", [
            "head" => $head,
        ]);
    }

    /**
     * SITE ARTICLES
     * @param array|null $data
     */
    public function articles(?array $data): void
    {
        $head = $this->seo->render(
            "Artigos - " . CONF_SITE_NAME,
            "Confira as artigos e dicas.",
            url("/artigos"),
            theme("/assets/images/share.jpg")
        );

        $articles = (new Post())->findPost();
        $pager = new Pager(url("/artigos/p/"));
        $pager->pager($articles->count(), 9, ($data['page'] ?? 1));

        echo $this->view->render("pages/articles", [
            "head" => $head,
            "articles" => $articles->order("post_at DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * SITE articles CATEGORY
     * @param array $data
     */
    public function articlesCategory(array $data): void
    {
        $categoryUri = filter_var($data["category"], FILTER_SANITIZE_STRIPPED);
        $category = (new Category())->findByUri($categoryUri);

        if (!$category) {
            redirect("/artigos");
        }

        $articlesCategory = (new Post())->findPost("category = :c", "c={$category->id}");
        $page = (!empty($data['page']) && filter_var($data['page'], FILTER_VALIDATE_INT) >= 1 ? $data['page'] : 1);
        $pager = new Pager(url("/artigos/em/{$category->uri}/"));
        $pager->pager($articlesCategory->count(), 9, $page);

        $head = $this->seo->render(
            "Artigos em {$category->title} - " . CONF_SITE_NAME,
            $category->description,
            url("/artigos/em/{$category->uri}/{$page}"),
            ($category->cover ? image($category->cover, 1200, 628) : theme("/assets/images/share.jpg"))
        );

        echo $this->view->render("pages/articles", [
            "head" => $head,
            "title" => "Artigos em <strong>{$category->title}</strong>",
            "desc" => $category->description,
            "articles" => $articlesCategory
                ->limit($pager->limit())
                ->offset($pager->offset())
                ->order("post_at DESC")
                ->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * SITE articles SEARCH
     * @param array $data
     */
    public function articlesSearch(array $data): void
    {
        if (!empty($data['s'])) {
            $search = str_search($data['s']);
            echo json_encode(["redirect" => url("/artigos/buscar/{$search}/1")]);
            return;
        }

        $search = str_search($data['search']);
        $page = (filter_var($data['page'], FILTER_VALIDATE_INT) >= 1 ? $data['page'] : 1);


        if ($search == "all") {
            redirect("/artigos");
        }

        $head = $this->seo->render(
            "Pesquisa por {$search} - " . CONF_SITE_NAME,
            "Confira os resultados de sua pesquisa para {$search}",
            url("/artigos/buscar/{$search}/{$page}"),
            theme("/assets/images/share.jpg")
        );

        $articlesSearch = (new Post())->findPost(
            "MATCH(title, subtitle, content) AGAINST(:s IN BOOLEAN MODE)",
            "s={$search}*"
        );

        if (!$articlesSearch->count()) {
            echo $this->view->render("pages/articles", [
                "head" => $head,
                "title" => "Pesquisa por: <strong>{$search}</strong>",
                "search" => $search,
            ]);
            return;
        }

        $pager = new Pager(url("artigos/buscar/{$search}/"));
        $pager->pager($articlesSearch->count(), 9, $page);


        echo $this->view->render("pages/articles", [
            "head" => $head,
            "title" => "Pesquisa por: <strong>{$search}</strong>",
            "search" => $search,
            "articles" => $articlesSearch->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * SITE articles POST
     * @param array $data
     */
    public function articlesPost(array $data): void
    {
        $uri = filter_var($data['uri'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $post = (new Post())->findPost("uri = :uri", "uri={$uri}")->fetch();
        if (!$post) {
            redirect("/404");
        }

        $category = $post->category();
        $author = $post->author();
        $categories = (new Category())->find()->order("title ASC")->fetch(true) ?? [];
        $recent = (new Post())
            ->findPost("id != :id", "id={$post->id}")
            ->order("post_at DESC")
            ->limit(3)
            ->fetch(true) ?? [];
        $related = [];
        if ($category) {
            $related = (new Post())
                ->findPost("category = :category AND id != :id", "category={$category->id}&id={$post->id}")
                ->order("post_at DESC")
                ->limit(3)
                ->fetch(true) ?? [];
        }

        $user = Auth::user();
        if (!$user || $user->level < 5) {
            $post->views += 1;
            $post->save();
        }

        $head = $this->seo->render(
            "{$post->title} - " . CONF_SITE_NAME,
            str_limit_chars(strip_tags($post->content), 120),
            url("/artigos/{$post->uri}"),
            ($post->cover ? image($post->cover, 1200, 628) : theme("/assets/images/share.jpg"))
        );

        echo $this->view->render("pages/articles-post", [
            "head" => $head,
            "post" => $post,
            "category" => $category,
            "author" => $author,
            "categories" => $categories,
            "recent" => $recent,
            "related" => $related
        ]);
    }

    public function pages(array $data): void
    {
        $page = (new Page())->findByUri($data['uri']);
        if (!$page) {
            redirect("/404");
        }

        $user = Auth::user();
        if (!$user || $user->level < 5) {
            $page->views += 1;
            $page->save();
        }

        $head = $this->seo->render(
            "{$page->title} - " . CONF_SITE_NAME,
            str_limit_chars(strip_tags($page->content), 120),
            url("/page/{$page->uri}"),
            ($page->cover ? image($page->cover, 1200, 628) : theme("/assets/images/share.jpg"))
        );

        echo $this->view->render("page", [
            "head" => $head,
            "page" => $page
        ]);
    }

    public function legal(array $data): void
    {
        $requestPath = trim((string)parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH), "/");
        $uri = basename($requestPath);
        if (!in_array($uri, ["politica-de-privacidade", "termos-de-uso"], true)) {
            redirect("/ops/404");
            return;
        }

        $page = (new Page())->findByUri($uri);
        if (!$page) {
            $page = (object)$this->legalFallback($uri);
        }

        $head = $this->seo->render(
            "{$page->title} - " . CONF_SITE_NAME,
            str_limit_chars(strip_tags($page->content), 150),
            url("/{$page->uri}"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/legal", ["head" => $head, "page" => $page]);
    }

    private function legalFallback(string $uri): array
    {
        if ($uri === "politica-de-privacidade") {
            return [
                "uri" => $uri,
                "title" => "Política de Privacidade",
                "content" => '<h2>Como tratamos seus dados</h2><p>A Connect Condomínios utiliza os dados fornecidos por clientes, moradores, síndicos e visitantes para prestar seus serviços, responder solicitações, cumprir obrigações legais e manter a segurança das operações.</p><h2>Dados coletados</h2><p>Podemos tratar dados de identificação, contato, informações condominiais e registros técnicos de acesso, sempre limitados às finalidades informadas e necessárias.</p><h2>Compartilhamento e segurança</h2><p>Os dados somente são compartilhados com fornecedores essenciais, autoridades competentes ou quando houver obrigação legal. Adotamos medidas técnicas e administrativas para evitar acesso, perda ou uso indevido.</p><h2>Seus direitos</h2><p>Você pode solicitar confirmação, acesso, correção, portabilidade ou exclusão de dados, observados os prazos e deveres legais, pelo e-mail <a href="mailto:' . htmlspecialchars(CONF_MAIL_SUPPORT) . '">' . htmlspecialchars(CONF_MAIL_SUPPORT) . '</a>.</p><p><small>Última atualização: ' . date('d/m/Y') . '.</small></p>'
            ];
        }

        return [
            "uri" => $uri,
            "title" => "Termos de Uso",
            "content" => '<h2>Uso dos serviços</h2><p>Ao acessar este site e utilizar os serviços da Connect Condomínios, você concorda em fornecer informações verdadeiras, respeitar a legislação e não praticar atos que prejudiquem a plataforma, seus usuários ou terceiros.</p><h2>Conteúdo e disponibilidade</h2><p>As informações do site têm caráter institucional. Recursos, prazos e condições comerciais são formalizados nas respectivas propostas e contratos. Podemos atualizar conteúdos e funcionalidades para melhorar os serviços.</p><h2>Responsabilidades</h2><p>Cada usuário é responsável pela segurança de suas credenciais e pelas ações realizadas em sua conta. Links e serviços de terceiros seguem seus próprios termos e políticas.</p><h2>Contato</h2><p>Dúvidas sobre estes termos podem ser enviadas para <a href="mailto:' . htmlspecialchars(CONF_MAIL_SUPPORT) . '">' . htmlspecialchars(CONF_MAIL_SUPPORT) . '</a>.</p><p><small>Última atualização: ' . date('d/m/Y') . '.</small></p>'
        ];
    }

    public function auth(?array $data):void{

        if(!$this->user = Auth::user()){
            $this->message->warning("Efetue login para acessar o APP.")->flash();
            redirect("/login");
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " - " . CONF_SITE_TITLE,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/auth", [
            "head" => $head,
        ]);
    }


    /**
     * SITE LOGIN
     * @param null|array $data
     */
    public function login(?array $data): void
    {
        if(Auth::user()){
            if(Auth::user()->privacy == "reject"){
                redirect("/termos");
            }else{
                redirect("/redirect");
            }
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (request_limit("weblogin", 8, 60 * 5)) {
                $json['message'] = $this->message->error("Você já efetuou 3 tentativas, esse é o limite. Por favor, aguarde 5 minutos para tentar novamente!")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['email']) || empty($data['password'])) {
                $json['message'] = $this->message->warning("Informe seu login e senha para entrar")->render();
                echo json_encode($json);
                return;
            }

            $save = (!empty($data['save']) ? true : false);
            $auth = new Auth();
            $login = $auth->login($data['email'], $data['password'], $save);

            if ($login) {
                $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . " vamos conhecer os termos de uso para utilizar o sistema.")->flash();
                $json['redirect'] = url("/termos");
            } else {
                $json['message'] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Entrar - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/login"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/login", [
            "head" => $head,
            "cookie" => filter_input(INPUT_COOKIE, "authEmail")
        ]);
    }

    /**
     * SITE PASSWORD FORGET
     * @param null|array $data
     */
    public function forget(?array $data)
    {
        if (Auth::user()) {
            redirect("/redirect");
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["email"])) {
                $json['message'] = $this->message->info("Informe seu e-mail para continuar")->render();
                echo json_encode($json);
                return;
            }

            if (request_repeat("webforget", $data["email"])) {
                $json['message'] = $this->message->error("Ooops! Você já tentou este e-mail antes")->render();
                echo json_encode($json);
                return;
            }

            $auth = new Auth();
            if ($auth->forget($data["email"])) {
                $json["message"] = $this->message->success("Acesse seu e-mail para recuperar a senha")->render();
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Recuperar Senha - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/forget", [
            "head" => $head
        ]);
    }

    /**
     * SITE FORGET RESET
     * @param array $data
     */
    public function reset(array $data): void
    {
        if (Auth::user()) {
            redirect("/redirect");
        }
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["password"]) || empty($data["password_re"])) {
                $json["message"] = $this->message->info("Informe e repita a senha para continuar")->render();
                echo json_encode($json);
                return;
            }

            list($code, $email) = explode(":", $data["code"]);
            $auth = new Auth();

            if ($auth->reset($email, $code, $data["password"], $data["password_re"])) {
                $this->message->success("Senha alterada com sucesso.")->flash();
                $json["redirect"] = url("/login");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/reset", [
            "head" => $head,
            "code" => $data["code"]
        ]);
    }

    /**
     * SITE CONFIRM
     * @param array $data
     */
    public function confirmation(array $data): void
    {
        if (Auth::user()) {
            redirect("/redirect");
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["password"]) || empty($data["password_re"])) {
                $json["message"] = $this->message->info("Informe e repita a senha para continuar")->render();
                echo json_encode($json);
                return;
            }

            list($code, $email) = explode(":", $data["code"]);
            $auth = new Auth();

            if ($auth->reset($email, $code, $data["password"], $data["password_re"])) {
                $user = (new User())->findByEmail($email);
                if ($user && $user->status != "confirmed") {
                    $user->status = "confirmed";
                    $user->send = "1";
                    $user->save();
                }
                $this->message->success("Senha cadastrada com sucesso :)")->flash();
                $json["redirect"] = url("/login");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/confirmation", [
            "head" => $head,
            "code" => $data["code"]
        ]);
    }

    /**
     * SITE TERMS
     */
    public function terms(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if ($this->user->privacy == "accept") {
            $this->message->success("Seja bem-vindo(a) de volta " . Auth::user()->first_name . "!")->flash();
            redirect("/redirect");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['confirm'])) {
                $json['message'] = $this->message->error("Você precisa aceitar os termos e política de privacidade.")->render();
                echo json_encode($json);
                return;
            }

            if ($this->user && $this->user->privacy != "accept") {
                $this->user->privacy = "accept";
                $this->user->save();

                $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . "!")->flash();
                $json['redirect'] = url("/redirect");
            }

        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " - Termos de uso",
            CONF_SITE_DESC,
            url("/termos"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/terms", [
            "head" => $head
        ]);
    }

    public function privacy(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['confirm'])) {
                $json['message'] = $this->message->error("Você precisa aceitar os termos e política de privacidade.")->render();
                echo json_encode($json);
                return;
            }

            if ($this->user && $this->user->privacy != "accept") {
                $this->user->privacy = "accept";
            }

            if (!$this->user->save()) {
                $json["message"] = $this->user->message()->render();
                echo json_encode($json);
                return;
            }

            if ($this->user && $this->user->privacy == "accept") {
                $json["message"] = $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . "!")->flash();
                echo json_encode(["redirect" => url("/redirect")]);
                return;
            }

        }
    }

    public function redirect(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if($this->user->level > 1){
            redirect("/auth");
        }else{
            redirect("/app");
        }

    }

    /**
     * SITE NAV ERROR
     * @param array $data
     */
    public function error(array $data): void
    {
        $error = new \stdClass();

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $requestedError = (string)($data['errcode'] ?? '404');
        $httpStatus = in_array((int)$requestedError, [403, 404, 500, 503], true) ? (int)$requestedError : (in_array($requestedError, ['problemas', 'manutencao', 'indisponivel'], true) ? 503 : 404);
        http_response_code($httpStatus);

        switch ($data['errcode']) {
            case "indisponivel":
                $error->code = "503";
                $error->title = "Site temporariamente indisponível";
                $error->message = "Este site está fora do ar no momento. Nossa equipe já está cuidando disso e o acesso será restabelecido em breve.";
                $error->linkTitle = "FALAR COM O SUPORTE";
                $error->link = "mailto:" . CONF_MAIL_SUPPORT;
                break;

            case "problemas":
                $error->code = "OPS";
                $error->title = "Estamos enfrentando problemas!";
                $error->message = "Parece que nosso serviço não está diponível no momento. <br> Já estamos vendo isso mas caso precise, envie um e-mail :)";
                $error->linkTitle = "ENVIAR E-MAIL";
                $error->link = "mailto:" . CONF_MAIL_SUPPORT;
                break;

            case "manutencao":
                $error->code = "OPS";
                $error->title = "Desculpe. Estamos em manutenção!";
                $error->message = "Voltamos logo! Por hora estamos trabalhando para melhorar nosso conteúdo para você controlar melhor as suas contas :P";
                $error->linkTitle = null;
                $error->link = null;
                break;

            default:
                $error->code = $data['errcode'];
                $error->title = "Ooops. Conteúdo indispinível :/";
                $error->message = "Sentimos muito, mas o conteúdo que você tentou acessar não existe, está indisponível no momento ou foi removido :/";
                $error->linkTitle = "Continue navegando!";
                $error->link = url_back();
                break;
        }

        $head = $this->seo->render(
            "{$error->code} | {$error->title}",
            $error->message,
            url("/ops/{$error->code}"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("pages/error", [
            "head" => $head,
            "error" => $error
        ]);
    }

    public function unavailable(): void
    {
        $this->error(["errcode" => "indisponivel"]);
    }
}
