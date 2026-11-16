<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Limite por IP que completa o request_limit() de sessão (ADR 0326).
 *
 * O request_limit() conta por sessão: quem não manda o cookie ganha uma sessão nova a cada pedido e
 * nunca chega ao limite. Este contador fica em arquivo (storage/temp/rate-limit), funciona na
 * hospedagem compartilhada sem Redis e guarda só o sha256 da chave com o IP, nunca o IP em claro.
 * Janela fixa: o primeiro pedido abre a janela de $seconds; ao passar de $limit, recusa até ela fechar.
 */
final class IpRateLimit
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? dirname(__DIR__, 2) . '/storage/temp/rate-limit', '/');
    }

    /** Limite do IP para um limite de sessão: folga para várias pessoas atrás do mesmo IP (portaria, escritório). */
    public static function ipLimitFor(int $sessionLimit): int
    {
        return max($sessionLimit * 5, 20);
    }

    /** Conta um pedido e devolve true quando o IP passou do limite na janela. Falha ao gravar = não bloqueia. */
    public function hit(string $key, string $ip, int $limit, int $seconds, ?int $now = null): bool
    {
        $now ??= time();
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }
        $file = $this->dir . '/' . hash('sha256', $key . '|' . $ip);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $data = json_decode((string)stream_get_contents($handle), true);
            $reset = is_array($data) ? (int)($data['reset'] ?? 0) : 0;
            $count = is_array($data) ? (int)($data['count'] ?? 0) : 0;
            if ($reset <= $now) {
                $reset = $now + max(1, $seconds);
                $count = 0;
            }
            $count++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode(['reset' => $reset, 'count' => $count]));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if (random_int(1, 200) === 1) {
            $this->purge($now);
        }
        return $count > $limit;
    }

    /** Apaga contadores parados há mais de um dia (cada IP gera um arquivo pequeno; limite de inodes da hospedagem). */
    public function purge(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file) && (int)@filemtime($file) < $now - 86400 && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
