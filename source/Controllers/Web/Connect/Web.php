<?php

namespace Source\Controllers\Web\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Post\Category;
use Source\Models\Post\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Brief\AppBrief;
use Source\Support\Pager;
use Source\Support\Proposal\ProposalService;
use Source\Services\Privacy\ConsentLog;
use Source\Services\Privacy\LegalDocuments;

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
        // Página inicial leva os dados da empresa para o Google (JSON-LD, ADR 0399).
        $head = $this->seo->business()->render(
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

    /**
     * Mapa do site para o Google: páginas públicas, artigos e Central de Ajuda (ADR 0399).
     */
    public function sitemap(): void
    {
        header("Content-Type: application/xml; charset=utf-8");
        header("Cache-Control: public, max-age=3600");
        echo \Source\Support\Sitemap::fromDatabase()->render();
    }

    public function sales(): void
    {

        $head = $this->seo->render(
            "Porque escolher a " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/solicite-sua-proposta"),
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
            echo json_encode(["redirect" => url("/artigos/buscar/" . rawurlencode($search) . "/1")]);
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
            url("/artigos/buscar/" . rawurlencode($search) . "/{$page}"),
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

        $pager = new Pager(url("artigos/buscar/" . rawurlencode($search) . "/"));
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

    /**
     * Prova da escolha do banner de cookies (LGPD art. 8º, § 2º; ADR 0604). Responde 204 sempre que o pedido é válido:
     * o cookie da escolha já foi gravado pelo navegador, aqui só fica o registro (hash do IP e navegador, sem IP).
     */
    public function cookieConsent(array $data): void
    {
        if (!csrf_verify($data) || request_limit('cookie_consent', 20, 3600)) {
            http_response_code(429);
            return;
        }
        $granted = [];
        foreach (array_keys(LegalDocuments::cookieCategories()) as $key) {
            $granted[] = $key . '=' . (($data[$key] ?? '0') === '1' ? '1' : '0');
        }
        try {
            (new ConsentLog())->record('cookies', str_contains(implode(',', $granted), '=1'), LegalDocuments::cookieVersion(), 'site',
                ['detail' => implode(',', $granted)]);
        } catch (\Throwable $e) {
            \Source\Support\AppLogger::log('error', 'Escolha de cookies não registrada', ['event_type' => 'privacy_consent_failed', 'error' => $e->getMessage(), 'status' => 'open'], 'privacy');
        }
        http_response_code(204);
    }

    /**
     * Política de Privacidade e Termos de Uso (ADR 0604). O texto vem do código, com versão (LegalDocuments), para cada
     * aceite apontar para um texto que não muda depois. Página com o mesmo endereço no Studio não substitui mais o texto.
     */
    public function legal(array $data): void
    {
        $requestPath = trim((string)parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH), "/");
        $documents = [
            "politica-de-privacidade" => ["Política de Privacidade", "privacidade", LegalDocuments::PRIVACY,
                "Como a " . CONF_SITE_NAME . " trata dados pessoais: quais dados, para quê, por quanto tempo e como exercer seus direitos."],
            "termos-de-uso" => ["Termos de Uso", "termos", LegalDocuments::TERMS,
                "Regras de uso do site, do ERP, do portal e do aplicativo do morador da " . CONF_SITE_NAME . "."],
        ];
        $uri = basename($requestPath);
        if (!isset($documents[$uri])) {
            redirect("/ops/404");
            return;
        }
        [$title, $template, $version, $description] = $documents[$uri];
        $head = $this->seo->render("{$title} - " . CONF_SITE_NAME, $description, url("/{$uri}"), theme("/assets/images/share.jpg"));
        echo $this->view->render("pages/legal", [
            "head" => $head,
            "title" => $title,
            "template" => $template,
            "versionLabel" => LegalDocuments::label($version),
        ]);
    }

    /**
     * SITE PASSWORD FORGET
     * @param null|array $data
     */
    public function forget(?array $data)
    {
        if (Auth::user()) {
            redirect("/");
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

            if (request_limit("webforget_limit", 5, 900)) {
                $json['message'] = $this->message->warning("Muitas tentativas. Aguarde alguns minutos.")->render();
                echo json_encode($json);
                return;
            }

            // Mesma resposta para conta existente ou não (sem enumeração de cadastros).
            $generic = "Se houver uma conta com esse e-mail ou CPF, enviaremos um link para criar uma nova senha.";
            if (request_repeat("webforget", $data["email"])) {
                $json["message"] = $this->message->success($generic)->render();
                echo json_encode($json);
                return;
            }

            $auth = new Auth();
            if ($auth->forget($data["email"])) {
                $json["message"] = $this->message->success($generic)->render();
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
            redirect("/");
        }
        $data = sanitize_keep_password($data);

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

            if (request_limit("webreset_limit", 10, 900)) {
                $json['message'] = $this->message->warning("Muitas tentativas. Aguarde alguns minutos.")->render();
                echo json_encode($json);
                return;
            }

            // O link leva só o token (sem e-mail); o formato é validado no serviço.
            $auth = new Auth();
            if ($auth->resetWithToken((string)($data["code"] ?? ""), $data["password"], $data["password_re"])) {
                $this->message->success("Senha alterada com sucesso.")->flash();
                $json["redirect"] = url("/studio/login");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        if (!(new Auth())->resetTokenIsValid((string)($data["code"] ?? ""))) {
            $this->message->error("O link de recuperação é inválido ou expirou. Solicite um novo.")->flash();
            redirect("/forget");
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("pages/login/reset", [
            "head" => $head,
            "code" => $data["code"]
        ]);
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
