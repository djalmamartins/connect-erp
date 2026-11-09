<?php

declare(strict_types=1);

namespace Source\Support\Erp;

use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Condomínio em contexto no ERP (ADR 0030).
 *
 * O condomínio escolhido na barra superior vale para todas as telas e aparece na URL logo
 * depois da base do ERP: erp.connect.lab/residencial-exemplo/cadastros/sindico-e-conselho.
 * Sem slug na URL, o contexto é "todos os condomínios do escopo do usuário".
 *
 * Fluxo por requisição (index.php, antes do dispatch):
 *  1. boot(): tira o slug do REQUEST_URI para que as rotas continuem as mesmas;
 *  2. resolve(): confere o slug contra o escopo do usuário (CondominiumScope) e devolve um
 *     redirecionamento quando a URL não é a canônica (URL antiga sem slug com condomínio na
 *     sessão, /todos/..., ?condominio= vindo de um filtro).
 *
 * O slug é derivado do nome (sem coluna nova). Nomes repetidos ou iguais a uma rota do ERP
 * ganham o id no fim (residencial-exemplo-12). Slug fora do escopo responde 404, sem revelar
 * se o condomínio existe. A autorização continua no servidor: o contexto só restringe.
 */
final class CondominiumContext
{
    public const SESSION_KEY = 'erpCondominium';

    /** Segmento explícito para "todos os condomínios"; redireciona para a URL sem slug. */
    public const ALL_SEGMENT = 'todos';

    /** Parâmetro de filtro que troca o contexto (listas e links "ver deste condomínio"). */
    public const FILTER_PARAM = 'condominio';

    /** Caminhos que não dependem do condomínio: nunca levam slug. */
    public const CONTEXT_FREE = ['login', 'sair', 'recuperar-senha', 'seguranca', 'contexto', 'studio', 'forget', 'reset', 'api', 'erp'];

    /** @var list<string> */
    private static array $reserved = [];
    private static bool $booted = false;
    /** Slug pedido na URL (null = sem slug). */
    private static ?string $requested = null;
    /** Prefixo do ERP no caminho (base do projeto + /erp fora do host do ERP). */
    private static string $prefix = '';
    /** Caminho relativo ao ERP, já sem o slug. */
    private static string $path = '/';
    private static string $originalUri = '/';
    /** @var array{id:int,name:string,slug:string}|null */
    private static ?array $current = null;
    /** @var list<array{id:int,name:string,slug:string}> */
    private static array $options = [];

    /** Volta ao estado inicial (testes e smoke). */
    public static function reset(): void
    {
        self::$reserved = [];
        self::$booted = false;
        self::$requested = null;
        self::$prefix = '';
        self::$path = '/';
        self::$originalUri = '/';
        self::$current = null;
        self::$options = [];
    }

    public static function slugify(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', '&' => ' e ',
        ]);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    }

    /**
     * Slugs únicos por condomínio. Nome repetido, vazio ou igual a uma rota reservada recebe
     * "-{id}"; assim o slug nunca esconde uma rota do ERP.
     *
     * @param list<array{id:int,name:string}> $options
     * @param list<string> $reserved
     * @return list<array{id:int,name:string,slug:string}>
     */
    public static function withSlugs(array $options, array $reserved = []): array
    {
        $base = [];
        $count = [];
        foreach ($options as $option) {
            $slug = self::slugify((string)$option['name']);
            $base[(int)$option['id']] = $slug;
            $count[$slug] = ($count[$slug] ?? 0) + 1;
        }
        $blocked = array_flip(array_merge($reserved, self::CONTEXT_FREE, [self::ALL_SEGMENT]));
        $result = [];
        foreach ($options as $option) {
            $id = (int)$option['id'];
            $slug = $base[$id];
            if ($slug === '' || $count[$slug] > 1 || isset($blocked[$slug]) || ctype_digit($slug)) {
                $slug = ltrim($slug . '-' . $id, '-');
            }
            $result[] = ['id' => $id, 'name' => (string)$option['name'], 'slug' => $slug];
        }
        return $result;
    }

    /**
     * Separa o slug do caminho relativo ao ERP: "/residencial-exemplo/cadastros" vira
     * ["residencial-exemplo", "/cadastros"]. Um segmento reservado (rota existente) não é slug.
     *
     * @param list<string> $reserved
     * @return array{0: ?string, 1: string}
     */
    public static function split(string $path, array $reserved): array
    {
        $path = '/' . ltrim($path, '/');
        $segments = explode('/', substr($path, 1), 2);
        $first = $segments[0];
        if ($first === '' || in_array($first, $reserved, true) || in_array($first, self::CONTEXT_FREE, true) || !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $first)) {
            return [null, $path];
        }
        return [$first, '/' . ($segments[1] ?? '')];
    }

    /** O caminho (relativo ao ERP) depende do condomínio? */
    public static function aware(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0];
        return !in_array($first, self::CONTEXT_FREE, true);
    }

    /**
     * Caminho relativo ao ERP com o slug do condomínio (ou sem slug para "todos").
     *
     * @param array{slug:string}|null $condominium
     */
    public static function pathFor(string $path, ?array $condominium): string
    {
        $path = '/' . ltrim($path, '/');
        if ($condominium === null || !self::aware($path)) {
            return $path;
        }
        return '/' . $condominium['slug'] . ($path === '/' ? '' : $path);
    }

    /** Caminho com o slug do contexto atual; usado por erp_url(). */
    public static function path(string $path): string
    {
        return self::pathFor($path, self::$current);
    }

    /**
     * 1º passo: tira o slug do REQUEST_URI (as rotas não mudam). Só age em requisições do ERP.
     *
     * @param list<string> $reserved primeiros segmentos das rotas registradas
     */
    public static function boot(array $reserved, string $prefix): void
    {
        self::$reserved = array_values(array_unique($reserved));
        self::$prefix = rtrim($prefix, '/');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        self::$originalUri = $uri;
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if (self::$prefix !== '' && $path !== self::$prefix && !str_starts_with($path, self::$prefix . '/')) {
            return;
        }
        self::$booted = true;
        $relative = substr($path, strlen(self::$prefix)) ?: '/';
        [$slug, $rest] = self::split($relative, self::$reserved);
        self::$requested = $slug;
        self::$path = $rest;
        if ($slug !== null) {
            $query = parse_url($uri, PHP_URL_QUERY);
            $_SERVER['REQUEST_URI'] = (self::$prefix . $rest) . ($query !== null && $query !== false && $query !== '' ? '?' . $query : '');
        }
    }

    public static function booted(): bool
    {
        return self::$booted;
    }

    /**
     * 2º passo: valida o contexto contra o escopo e diz se a URL precisa mudar.
     *
     * @param list<array{id:int,name:string}> $options condomínios ativos do escopo do usuário
     * @param array<string, mixed> $query $_GET
     * @return string|null caminho relativo ao ERP (com query) para redirecionar, ou null
     */
    public static function resolve(array $options, ?int $sessionId, string $method, array $query, ?Session $session = null): ?string
    {
        self::$options = self::withSlugs($options, self::$reserved);
        self::$current = null;
        $isGet = strtoupper($method) === 'GET';
        $aware = self::aware(self::$path);

        if (self::$requested === self::ALL_SEGMENT) {
            $session?->unset(self::SESSION_KEY);
            return $isGet ? self::withQuery(self::$path, $query) : null;
        }
        if (self::$requested !== null) {
            $found = self::find(fn (array $o): bool => $o['slug'] === self::$requested);
            if ($found === null) {
                // Slug desconhecido ou fora do escopo: devolve o URI original para o router responder 404.
                $_SERVER['REQUEST_URI'] = self::$originalUri;
                return null;
            }
            self::$current = $found;
            $session?->set(self::SESSION_KEY, $found['id']);
        } elseif ($sessionId !== null && $sessionId > 0) {
            self::$current = self::find(fn (array $o): bool => $o['id'] === $sessionId);
            if (self::$current === null) {
                $session?->unset(self::SESSION_KEY);
            } elseif ($isGet && $aware) {
                // URL antiga (sem slug) com condomínio escolhido: leva para a URL com slug.
                return self::withQuery(self::path(self::$path), $query);
            }
        }

        if ($isGet && $aware && array_key_exists(self::FILTER_PARAM, $query)) {
            // Filtro de condomínio: troca o contexto da barra superior e tira o parâmetro da URL.
            $raw = is_scalar($query[self::FILTER_PARAM]) ? (string)$query[self::FILTER_PARAM] : '';
            unset($query[self::FILTER_PARAM]);
            $target = self::$current;
            if ($raw === '' || $raw === '0') {
                $target = null;
            } elseif (ctype_digit($raw)) {
                $target = self::find(fn (array $o): bool => $o['id'] === (int)$raw) ?? self::$current;
            }
            if ($target === null) {
                $session?->unset(self::SESSION_KEY);
            } else {
                $session?->set(self::SESSION_KEY, $target['id']);
            }
            return self::withQuery(self::pathFor(self::$path, $target), $query);
        }
        return null;
    }

    /**
     * Chamado pelo index.php: resolve com o usuário e o escopo reais. Devolve a URL completa
     * para redirecionar, ou null.
     */
    public static function bootRequest(array $reserved): ?string
    {
        $prefix = rtrim((string)(parse_url(url(), PHP_URL_PATH) ?: ''), '/') . erp_base();
        self::boot($reserved, $prefix);
        if (!self::$booted || str_starts_with(self::$path . '/', '/api/')) {
            return null;
        }
        $user = Auth::user();
        if ($user === null || !Access::can('erp.access', $user)) {
            return null;
        }
        $session = new Session();
        $sessionId = $session->has(self::SESSION_KEY) ? (int)$session->{self::SESSION_KEY} : null;
        $target = self::resolve(self::scopeOptions($user), $sessionId, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $session);
        return $target === null ? null : url(erp_base() . $target);
    }

    /** @return list<array{id:int,name:string}> condomínios ativos do escopo, para o seletor. */
    public static function scopeOptions(User $user): array
    {
        try {
            $rows = (new CondominiumScope())->allowed($user);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'condominium_options_failed', 'users_id' => $user->id ?? null]);
            return [];
        }
        $rows = array_filter($rows, static fn (array $r): bool => ($r['status'] ?? 'active') === 'active');
        return array_values(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows));
    }

    /** @return array{id:int,name:string,slug:string}|null */
    public static function current(): ?array
    {
        return self::$current;
    }

    public static function id(): ?int
    {
        return self::$current['id'] ?? null;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public static function options(): array
    {
        return self::$options;
    }

    /** @return array{id:int,name:string,slug:string}|null */
    public static function option(int $id): ?array
    {
        return self::find(static fn (array $o): bool => $o['id'] === $id);
    }

    /** Caminho atual relativo ao ERP, sem o slug (o "back" do seletor). */
    public static function relativePath(): string
    {
        return self::$path;
    }

    /**
     * Define o contexto quando a resolução não passou pelo index.php (smoke de renderização,
     * telas que trocam de condomínio). Só aceita um condomínio de $options.
     *
     * @param list<array{id:int,name:string}> $options
     */
    public static function use(array $options, ?int $id): void
    {
        self::$options = self::withSlugs($options, self::$reserved);
        self::$current = $id === null ? null : self::option($id);
    }

    /**
     * Ao abrir um registro de outro condomínio, a barra superior passa a mostrar o dele:
     * devolve o caminho com o slug certo, ou null quando já está certo (ou o registro vale
     * para todos os condomínios).
     */
    public static function follow(?int $condominiumId): ?string
    {
        if ($condominiumId === null || $condominiumId <= 0 || self::id() === $condominiumId) {
            return null;
        }
        $target = self::option($condominiumId);
        if ($target === null) {
            return null;
        }
        (new Session())->set(self::SESSION_KEY, $target['id']);
        return self::withQuery(self::pathFor(self::$path, $target), $_GET);
    }

    /** @param array<string, mixed> $query */
    private static function withQuery(string $path, array $query): string
    {
        unset($query['route']);
        $qs = http_build_query($query);
        return $path . ($qs !== '' ? '?' . $qs : '');
    }

    /** @return array{id:int,name:string,slug:string}|null */
    private static function find(callable $match): ?array
    {
        foreach (self::$options as $option) {
            if ($match($option)) {
                return $option;
            }
        }
        return null;
    }
}
