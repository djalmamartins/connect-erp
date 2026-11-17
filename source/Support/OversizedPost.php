<?php

declare(strict_types=1);

namespace Source\Support;

use Connect\Http\JsonResponse;

/**
 * Envio acima do post_max_size do PHP (ADR 0363).
 *
 * Quando o corpo passa do post_max_size, o PHP descarta tudo: $_POST e $_FILES chegam vazios, inclusive o token CSRF.
 * Sem esta conferência a tela respondia "Sessão expirada" ou "Envie um arquivo", sem dizer que o arquivo era grande
 * demais. Aqui, antes das rotas: API e pedidos por JavaScript recebem 413 em JSON; formulário comum volta para a página
 * de onde veio (só do mesmo host) com o aviso do limite.
 */
final class OversizedPost
{
    /**
     * Limite em bytes quando o pedido foi descartado por tamanho; null quando está tudo certo.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function limit(array $server, array $post, array $files, string|false $postMaxSize): ?int
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true) || $post !== [] || $files !== []) {
            return null;
        }
        $limit = DeployReadiness::bytes($postMaxSize);
        $length = (int)($server['CONTENT_LENGTH'] ?? 0);
        return $limit > 0 && $length > $limit ? $limit : null;
    }

    public static function message(int $limit): string
    {
        $mb = max(1, (int)floor($limit / 1048576));
        return "O envio passou do limite de {$mb} MB e não foi recebido. Envie um arquivo menor ou um de cada vez.";
    }

    /** @param array<string, mixed> $server */
    public static function wantsJson(array $server): bool
    {
        $path = (string)parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return str_starts_with($path, '/api/')
            || str_contains(strtolower((string)($server['HTTP_ACCEPT'] ?? '')), 'application/json')
            || strtolower((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Endereço para voltar: o Referer do mesmo host, senão a própria página pedida (por GET).
     *
     * @param array<string, mixed> $server
     */
    public static function backTo(array $server): string
    {
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $referer = (string)($server['HTTP_REFERER'] ?? '');
        $parts = parse_url($referer);
        if ($host !== '' && is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && strtolower((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')) === $host) {
            return $referer;
        }
        $path = (string)parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/';
    }

    /**
     * Corpo do 413. A API lê error.message; a Central de Ajuda e o widget do Help Desk leem success/message.
     *
     * @return array{success: false, message: string, error: array{code: string, message: string}}
     */
    public static function jsonBody(string $message): array
    {
        return ['success' => false, 'message' => $message, 'error' => ['code' => 'payload_too_large', 'message' => $message]];
    }

    /** Chamado no index.php: responde e encerra quando o envio foi descartado. */
    public static function handle(): void
    {
        $limit = self::limit($_SERVER, $_POST, $_FILES, ini_get('post_max_size'));
        if ($limit === null) {
            return;
        }
        $message = self::message($limit);
        AppLogger::log('warning', 'Envio acima do post_max_size descartado.', [
            'event_type' => 'post_too_large', 'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0), 'limit' => $limit,
        ]);
        if (self::wantsJson($_SERVER)) {
            JsonResponse::send(413, self::jsonBody($message));
            exit;
        }
        (new Message())->warning($message)->flash();
        header('Location: ' . self::backTo($_SERVER), true, 303);
        exit;
    }
}
