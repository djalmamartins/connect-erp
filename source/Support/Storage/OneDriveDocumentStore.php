<?php

declare(strict_types=1);

namespace Source\Support\Storage;

use Source\Support\AppLogger;

/**
 * Arquivos no OneDrive empresarial da Connect pelo Microsoft Graph (ADR 0373, ROADMAP_V1 §3.5).
 *
 * - Acesso por aplicativo registrado no Entra ID (client credentials), sem depender da senha de uma pessoa.
 * - Os arquivos ficam em {pasta}/{caminho relativo} no drive configurado; o caminho é o mesmo do disco local, então a
 *   migração é só copiar a árvore.
 * - path() devolve uma cópia local num cache: quem usa o arquivo (download, PDF, antivírus) continua lendo do disco.
 *   O download segue o endereço temporário que o Graph devolve (302) sem mandar o token para outro host.
 * - Se a Microsoft não responder ou a autorização expirar, path() devolve null (o arquivo fica indisponível) e put()
 *   devolve false (o envio falha com mensagem); o resto do ERP continua.
 */
final class OneDriveDocumentStore implements DocumentStore
{
    public const GRAPH = 'https://graph.microsoft.com/v1.0';
    /** Até 4 MB vai num PUT só; acima, sessão de envio em partes múltiplas de 320 KiB. */
    public const SIMPLE_LIMIT = 4194304;
    public const CHUNK = 3276800;

    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(
        private string $tenant,
        private string $clientId,
        private string $clientSecret,
        private string $driveId,
        private string $folder,
        private string $cache,
        private GraphTransport $http = new CurlGraphTransport(),
    ) {
        $this->folder = trim($folder, '/');
        $this->cache = rtrim($cache, '/');
    }

    /** Endereço do item no drive: cada parte do caminho codificada. */
    public function itemUrl(string $relative): string
    {
        $parts = array_map('rawurlencode', array_filter(explode('/', $this->folder . '/' . $relative), 'strlen'));
        return self::GRAPH . '/drives/' . rawurlencode($this->driveId) . '/root:/' . implode('/', $parts) . ':';
    }

    public function put(string $relative, string $source, bool $move): bool
    {
        if (!LocalDocumentStore::safe($relative) || !is_file($source) || ($move && !is_uploaded_file($source))) {
            return false;
        }
        try {
            $size = (int)filesize($source);
            $ok = $size <= self::SIMPLE_LIMIT ? $this->putSimple($relative, $source) : $this->putSession($relative, $source, $size);
        } catch (\Throwable $error) {
            $this->warn('put', $relative, $error->getMessage());
            return false;
        }
        if ($ok) {
            $this->forget($relative);
            if ($move) {
                @unlink($source);
            }
        }
        return $ok;
    }

    public function path(string $relative): ?string
    {
        if (!LocalDocumentStore::safe($relative)) {
            return null;
        }
        $cached = $this->cache . '/' . $relative;
        if (is_file($cached)) {
            return $cached;
        }
        try {
            $answer = $this->http->send('GET', $this->itemUrl($relative) . '/content', $this->auth());
            if ($answer['status'] === 404) {
                return null;
            }
            if (in_array($answer['status'], [301, 302, 303, 307], true) && self::trusted($answer['location'])) {
                $answer = $this->http->send('GET', $answer['location']);   // endereço temporário, sem o token
            }
            if ($answer['status'] !== 200) {
                $this->warn('path', $relative, 'HTTP ' . $answer['status']);
                return null;
            }
            return $this->keep($cached, $answer['body']);
        } catch (\Throwable $error) {
            $this->warn('path', $relative, $error->getMessage());
            return null;
        }
    }

    public function delete(string $relative): void
    {
        if (!LocalDocumentStore::safe($relative)) {
            return;
        }
        $this->forget($relative);
        try {
            $answer = $this->http->send('DELETE', $this->itemUrl($relative), $this->auth());
            if (!in_array($answer['status'], [204, 404], true)) {
                $this->warn('delete', $relative, 'HTTP ' . $answer['status']);
            }
        } catch (\Throwable $error) {
            $this->warn('delete', $relative, $error->getMessage());
        }
    }

    /** Só segue redirecionamento HTTPS (o Graph manda para *.sharepoint.com ou *.1drv.com). */
    public static function trusted(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && ($parts['host'] ?? '') !== '';
    }

    private function putSimple(string $relative, string $source): bool
    {
        $answer = $this->http->send('PUT', $this->itemUrl($relative) . '/content',
            $this->auth() + ['Content-Type' => 'application/octet-stream'], (string)file_get_contents($source));
        return in_array($answer['status'], [200, 201], true);
    }

    private function putSession(string $relative, string $source, int $size): bool
    {
        $answer = $this->http->send('POST', $this->itemUrl($relative) . '/createUploadSession',
            $this->auth() + ['Content-Type' => 'application/json'], '{"item":{"@microsoft.graph.conflictBehavior":"replace"}}');
        $upload = (string)(json_decode($answer['body'], true)['uploadUrl'] ?? '');
        if ($answer['status'] !== 200 || !self::trusted($upload)) {
            return false;
        }
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            for ($offset = 0; $offset < $size; $offset += self::CHUNK) {
                $chunk = (string)fread($handle, self::CHUNK);
                $end = $offset + strlen($chunk) - 1;
                // O endereço da sessão já é autorizado: não leva o token.
                $part = $this->http->send('PUT', $upload, ['Content-Length' => (string)strlen($chunk),
                    'Content-Range' => "bytes {$offset}-{$end}/{$size}"], $chunk);
                $last = $end === $size - 1;
                if (($last && !in_array($part['status'], [200, 201], true)) || (!$last && $part['status'] !== 202)) {
                    $this->http->send('DELETE', $upload);
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }
        return true;
    }

    /** @return array{Authorization: string} */
    private function auth(): array
    {
        if ($this->token === null || time() >= $this->tokenExpires) {
            $answer = $this->http->send('POST', 'https://login.microsoftonline.com/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token',
                ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                    'grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ]));
            $data = json_decode($answer['body'], true);
            if ($answer['status'] !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
                throw new \RuntimeException('Autorização do OneDrive recusada (HTTP ' . $answer['status'] . ').');
            }
            $this->token = $data['access_token'];
            $this->tokenExpires = time() + max(60, (int)($data['expires_in'] ?? 3600) - 120);
        }
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function keep(string $cached, string $body): ?string
    {
        $directory = dirname($cached);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }
        $temporary = $cached . '.' . bin2hex(random_bytes(4)) . '.part';
        if (file_put_contents($temporary, $body) !== strlen($body) || !rename($temporary, $cached)) {
            @unlink($temporary);
            return null;
        }
        @chmod($cached, 0640);
        return $cached;
    }

    private function forget(string $relative): void
    {
        @unlink($this->cache . '/' . $relative);
    }

    private function warn(string $action, string $relative, string $reason): void
    {
        AppLogger::log('warning', 'OneDrive: falha ao acessar arquivo.', ['event_type' => 'onedrive_' . $action, 'path' => $relative, 'reason' => $reason]);
    }
}
