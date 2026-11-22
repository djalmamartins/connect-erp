<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Models\User;

/**
 * Alternador de ambientes Connect (ADR 0034): Studio, ERP, Help Desk, portal dos moradores, Connect Talk e site.
 * O Talk (atendimento por WhatsApp, ADR 0324) mora no host talk.<domínio> e aparece só para quem tem talk.access.
 *
 * Um só catálogo para o cabeçalho da sidebar do ERP e do Studio/Help Desk. Cada ambiente diz em
 * que host mora: com hosts próprios (erp.<domínio> e studio.<domínio>, ADR 0026) o ERP fica no
 * host do ERP e o Studio e o Help Desk no host do Studio; num host único (localhost, www) todos
 * ficam no mesmo host, em /erp, /studio e /helpdesk.
 *
 * Entre hosts diferentes o cookie de sessão não é o mesmo. Por isso o link passa por
 * /ambiente/{ambiente} no host de origem, que emite um código de uso único (60 s), e o host de
 * destino troca o código pela sessão em /ambiente/entrar/{ambiente}/{código}.
 *
 * Permissão: hoje todo usuário logado troca para qualquer ambiente (pedido do proprietário,
 * 09/11/2026). A permissão por usuário entra em canAccess(), o único ponto de decisão. Dentro de
 * cada ambiente a rota continua validando a própria permissão (studio.access, erp.access,
 * support.manage).
 */
final class Environments
{
    public const HANDOFF_TTL = 60;

    /**
     * host: 'erp' ou 'studio' quando o ambiente tem host próprio; 'site' para o host do site.
     * permission: permissão que canAccess() vai exigir quando a regra por usuário entrar.
     */
    private const ENVIRONMENTS = [
        'studio' => ['label' => 'Studio', 'description' => 'Gestão de conteúdo e operação', 'icon' => 'grid-outline', 'host' => 'studio', 'path' => '/studio/dash', 'permission' => 'studio.access', 'auth' => true],
        'erp' => ['label' => 'ERP', 'description' => 'Gestão administrativa', 'icon' => 'business-outline', 'host' => 'erp', 'path' => '/dashboard', 'permission' => 'erp.access', 'auth' => true],
        'helpdesk' => ['label' => 'SAC', 'description' => 'Atendimento e chamados', 'icon' => 'headset-outline', 'host' => 'studio', 'path' => '/helpdesk/tickets', 'permission' => 'support.manage', 'auth' => true],
        'residents' => ['label' => 'Moradores', 'description' => 'Aplicativo do morador', 'icon' => 'home-outline', 'host' => 'app', 'path' => '/', 'permission' => 'app.access', 'auth' => true],
        'talk' => ['label' => 'Talk', 'description' => 'Atendimento por WhatsApp', 'icon' => 'chatbubbles-outline', 'host' => 'talk', 'path' => '/', 'permission' => 'talk.access', 'auth' => true],
        'site' => ['label' => 'Site', 'description' => 'Site institucional', 'icon' => 'globe-outline', 'host' => 'site', 'path' => '/', 'permission' => 'site.access', 'auth' => false],
    ];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ENVIRONMENTS);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ENVIRONMENTS[$key]);
    }

    /**
     * Gancho da permissão por ambiente. Hoje: todo usuário logado (pedido de 09/11/2026).
     * Para a regra por usuário, troque o retorno por `$user->can(self::permission($key))`.
     */
    public static function canAccess(?User $user, string $key): bool
    {
        if ($key === 'talk') {
            // Só quem atende pelo WhatsApp (ADR 0324).
            return $user !== null && \Source\Support\Access::can('talk.access', $user);
        }
        return $user !== null && self::exists($key);
    }

    public static function permission(string $key): ?string
    {
        return self::ENVIRONMENTS[$key]['permission'] ?? null;
    }

    /**
     * Itens do alternador para o usuário, na ordem do catálogo.
     *
     * @return list<array{key:string,label:string,description:string,icon:string,href:string,current:bool}>
     */
    public static function menu(?User $user, string $current, ?string $host = null): array
    {
        $items = [];
        foreach (self::ENVIRONMENTS as $key => $environment) {
            if ($key === 'residents' && !self::residentsAvailable()) {
                continue;
            }
            if (!self::canAccess($user, $key)) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => $environment['label'],
                'description' => $environment['description'],
                'icon' => $environment['icon'],
                'href' => self::href($key, $host),
                'current' => $key === $current,
            ];
        }
        return $items;
    }

    /** O portal dos moradores só entra no alternador quando o tema existe (sem link morto). */
    public static function residentsAvailable(): bool
    {
        return function_exists('moves_container_path')
            && defined('CONF_VIEW_APP')
            && is_file(moves_container_path('residents', CONF_VIEW_APP) . '/default.php');
    }

    /** Papel do host: 'erp', 'studio', 'app', 'talk' ou 'main' (host único: localhost, www, domínio puro). */
    public static function hostRole(?string $host = null): string
    {
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('/^erp\.[a-z0-9.-]+(?::\d+)?$/', $host)) {
            return 'erp';
        }
        if (preg_match('/^studio\.[a-z0-9.-]+(?::\d+)?$/', $host)) {
            return 'studio';
        }
        if (preg_match('/^app\.[a-z0-9.-]+(?::\d+)?$/', $host)) {
            return 'app';
        }
        if (preg_match('/^talk\.[a-z0-9.-]+(?::\d+)?$/', $host)) {
            return 'talk';
        }
        return 'main';
    }

    /**
     * Host e caminho do ambiente a partir do host atual. $path troca a página inicial do ambiente
     * por outra (deep link); quem chama valida o caminho com safePath().
     *
     * @return array{host:string,path:string}
     */
    public static function target(string $key, ?string $host = null, ?string $path = null): array
    {
        if (!self::exists($key)) {
            throw new \InvalidArgumentException('Ambiente desconhecido.');
        }
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        $role = self::hostRole($host);
        $environment = self::ENVIRONMENTS[$key];
        $path ??= $environment['path'];

        // Talk no Raspberry Pi (ADR 0359): TALK_URL no .env do ERP diz onde ele está, mesmo fora de talk.<domínio>.
        if ($key === 'talk' && ($origin = self::talkOrigin()) !== null) {
            return ['host' => $origin['host'], 'path' => $path];
        }
        if ($key === 'talk' && $role === 'main') {
            // O Talk só existe no host próprio (ADR 0324): de um host único, vai para talk.<domínio>.
            return ['host' => 'talk.' . (string)preg_replace('/^www\./', '', $host), 'path' => $path];
        }
        if ($role === 'main') {
            // Host único: tudo no mesmo host; o ERP fica em /erp e o app do morador em /app (ADR 0302).
            return ['host' => $host, 'path' => match ($key) {
                'erp' => '/erp' . $path,
                'residents' => '/app' . ($path === '/' ? '' : $path),
                default => $path,
            }];
        }

        $base = (string)preg_replace('/^(?:erp|studio|app|talk)\./', '', $host);
        $targetHost = match ($environment['host']) {
            'erp' => 'erp.' . $base,
            'studio' => 'studio.' . $base,
            'app' => 'app.' . $base,
            'talk' => 'talk.' . $base,
            default => 'www.' . $base,
        };
        return ['host' => $targetHost, 'path' => $path];
    }

    /** Link do alternador: direto no mesmo host; entre hosts, pela troca com código. */
    public static function href(string $key, ?string $host = null): string
    {
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        $target = self::target($key, $host);
        if ($target['host'] === $host) {
            return url($target['path']);
        }
        if (!self::ENVIRONMENTS[$key]['auth']) {
            return self::absolute($target['host'], $target['path']);
        }
        return url('/ambiente/' . $key);
    }

    /** Endereço absoluto de uma página de um ambiente (ex.: link do Help Desk num e-mail do ERP). */
    public static function url(string $key, string $path, ?string $host = null): string
    {
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        $target = self::target($key, $host);
        if ($target['host'] === $host) {
            return url($path);
        }
        return self::absolute($target['host'], $path);
    }

    /**
     * Caminho de destino aceito para um ambiente (deep link do alternador, ADR 0142), ou null.
     *
     * Só caminho local (começa com uma barra, sem "//", sem barra invertida nem caracteres de
     * controle), fora de /ambiente, e dentro da área do ambiente: Help Desk em /helpdesk, Studio em
     * /studio. O ERP aceita qualquer caminho do host dele.
     */
    /** Sessão: ambiente a abrir depois do login do ERP (só o Talk, ADR 0359). */
    public const RETURN_KEY = 'environment_after_login';

    /**
     * Quem chega em /ambiente/talk sem sessão entra pelo login do ERP e volta para cá.
     * Guarda só a chave do ambiente, nunca um caminho vindo da requisição.
     */
    public static function rememberReturn(string $key): void
    {
        if ($key === 'talk') {
            (new \Source\Core\Session())->set(self::RETURN_KEY, $key);
        }
    }

    /** Caminho interno para voltar depois do login (uma vez só) ou null. */
    public static function takeReturn(): ?string
    {
        $session = new \Source\Core\Session();
        if (!$session->has(self::RETURN_KEY)) {
            return null;
        }
        $key = (string)$session->{self::RETURN_KEY};
        $session->unset(self::RETURN_KEY);
        return $key === 'talk' ? '/ambiente/talk' : null;
    }

    public static function safePath(string $key, ?string $path): ?string
    {
        $path = (string)$path;
        if (!self::exists($key) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//')
            || strlen($path) > 300 || preg_match('/[\\\\\x00-\x1f\x7f\s]/', $path) || preg_match('~^/ambiente(?:/|\?|$)~i', $path)) {
            return null;
        }
        $prefix = ['helpdesk' => '/helpdesk', 'studio' => '/studio'][$key] ?? null;
        if ($prefix !== null && !preg_match('~^' . preg_quote($prefix, '~') . '(?:[/?#]|$)~', $path)) {
            return null;
        }
        return $path;
    }

    /**
     * Link para uma página de um ambiente (ex.: o chamado no Help Desk a partir do ERP).
     * No mesmo host vai direto; em outro host passa pela troca com código, levando o destino em ?ir=.
     */
    public static function link(string $key, string $path, ?string $host = null): string
    {
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        $safe = self::safePath($key, $path);
        if ($safe === null) {
            return self::href($key, $host);
        }
        $target = self::target($key, $host, $safe);
        if ($target['host'] === $host) {
            return url($target['path']);
        }
        if (!self::ENVIRONMENTS[$key]['auth']) {
            return self::absolute($target['host'], $target['path']);
        }
        return url('/ambiente/' . $key . '?ir=' . rawurlencode($safe));
    }

    /**
     * Link guardado numa notificação, pronto para o clique no host atual, ou null se não for nosso.
     * Aceita caminho local e endereço absoluto do próprio host ou de um host irmão (erp.*, studio.*,
     * www.* do mesmo domínio); o host irmão passa pela troca com código.
     */
    public static function resolveLink(string $link, ?string $host = null): ?string
    {
        $host = strtolower($host ?? (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($link === '') {
            return null;
        }
        if ($link[0] === '/') {
            return str_starts_with($link, '//') || preg_match('/[\\\\\x00-\x1f\x7f]/', $link) ? null : $link;
        }
        $parts = parse_url($link);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $linkHost = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $linkHost === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $linkHostPort = $linkHost . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($linkHostPort === $host || $linkHost === $host) {
            return $link;
        }
        $base = static fn (string $value): string => (string)preg_replace('/^(?:erp|studio|app|www)\./', '', $value);
        if ($base($linkHostPort) !== $base($host) && $base($linkHost) !== $base($host)) {
            return null;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $role = self::hostRole($linkHostPort);
        if ($role === 'erp') {
            return self::link('erp', $path, $host);
        }
        if ($role === 'studio') {
            return self::link(str_starts_with($path, '/helpdesk') ? 'helpdesk' : 'studio', $path, $host);
        }
        if ($role === 'app') {
            return self::link('residents', $path, $host);
        }
        return $link;
    }

    public static function absolute(string $host, string $path): string
    {
        $talk = self::talkOrigin();
        $scheme = $talk !== null && $talk['host'] === $host ? $talk['scheme'] : self::scheme();
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    /**
     * Endereço do Talk vindo de TALK_URL (regra pura sobre o .env): só http/https, sem usuário, caminho ou consulta.
     * Inválido ou vazio = null, e o Talk segue em talk.<domínio>.
     *
     * @return array{scheme:string, host:string}|null
     */
    public static function talkOrigin(?string $url = null): ?array
    {
        $url = trim($url ?? (string)(getenv('TALK_URL') ?: ''));
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['query'])
            || trim((string)($parts['path'] ?? ''), '/') !== '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }
        return ['scheme' => $scheme, 'host' => $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '')];
    }

    private static function scheme(): string
    {
        $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || $forwarded === 'https';
        return $https ? 'https' : 'http';
    }

    /** Emite o código de uso único; o banco guarda só o hash. */
    public function issue(User $user, string $key): string
    {
        if (!self::exists($key)) {
            throw new \InvalidArgumentException('Ambiente desconhecido.');
        }
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM auth_environment_handoffs WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO auth_environment_handoffs (user_id, environment, token_hash, expires_at, created_ip) VALUES (:user, :environment, :hash, (NOW() + INTERVAL ' . self::HANDOFF_TTL . ' SECOND), :ip)');
        $stmt->execute([
            'user' => (int)$user->id,
            'environment' => $key,
            'hash' => hash('sha256', $token),
            'ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
        return $token;
    }

    /** Troca o código pela conta: uma vez só, dentro do prazo e para o ambiente emitido. */
    public function consume(string $token, string $key): ?int
    {
        if (!self::exists($key) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $pdo = $this->pdo();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('UPDATE auth_environment_handoffs SET used_at = NOW() WHERE token_hash = :hash AND environment = :environment AND used_at IS NULL AND expires_at >= NOW()');
        $stmt->execute(['hash' => $hash, 'environment' => $key]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $user = $pdo->prepare('SELECT user_id FROM auth_environment_handoffs WHERE token_hash = :hash');
        $user->execute(['hash' => $hash]);
        $id = (int)$user->fetchColumn();
        return $id > 0 ? $id : null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
