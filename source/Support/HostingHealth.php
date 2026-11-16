<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Saúde das rotinas da hospedagem (ADR 0333). O tools/hosting/cron.sh grava, ao terminar cada grupo, o sinal de vida em
 * storage/temp/heartbeat/<grupo> no formato "<epoch> <falhas>". Aqui se lê esse sinal e se diz, por grupo:
 *  - ready:   rodou dentro do prazo e sem falha;
 *  - failing: rodou dentro do prazo, mas algum comando do grupo falhou;
 *  - stale:   não roda há mais tempo que o prazo (o cron parou ou o grupo está travado);
 *  - unknown: nunca rodou nesta instalação (lab, ou cron ainda não configurado). Não conta como problema.
 *
 * Regra pura evaluate(); read() só lê os arquivos. Não sai caminho, comando nem mensagem de erro: só situações.
 */
final class HostingHealth
{
    /** Prazo de cada grupo em segundos: o intervalo do cron com folga. */
    public const LIMITS = ['minuto' => 600, 'hora' => 7800, 'dia' => 93600, 'backup' => 93600];

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/storage/temp/heartbeat';
    }

    /** @return array<string, array{at:int, failures:int}> */
    public static function read(?string $directory = null): array
    {
        $dir = $directory ?? self::directory();
        $beats = [];
        foreach (array_keys(self::LIMITS) as $group) {
            $file = $dir . '/' . $group;
            if (!is_file($file)) {
                continue;
            }
            $parts = preg_split('/\s+/', trim((string)@file_get_contents($file)));
            if (isset($parts[0]) && ctype_digit($parts[0])) {
                $beats[$group] = ['at' => (int)$parts[0], 'failures' => isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 0];
            }
        }
        return $beats;
    }

    /**
     * @param array<string, array{at:int, failures:int}> $beats
     * @return array{status:string, groups:array<string, array{status:string, age:?int}>}
     */
    public static function evaluate(array $beats, int $now): array
    {
        $groups = [];
        $problem = false;
        foreach (self::LIMITS as $group => $limit) {
            $beat = $beats[$group] ?? null;
            if ($beat === null) {
                $groups[$group] = ['status' => 'unknown', 'age' => null];
                continue;
            }
            $age = max(0, $now - $beat['at']);
            $status = $age > $limit ? 'stale' : ($beat['failures'] > 0 ? 'failing' : 'ready');
            $problem = $problem || $status !== 'ready';
            $groups[$group] = ['status' => $status, 'age' => $age];
        }
        return ['status' => $problem ? 'degraded' : 'ready', 'groups' => $groups];
    }

    /** Situação resumida para o /api/v1/health público: só as palavras, sem idade. */
    public static function publicChecks(?array $evaluation = null): array
    {
        $evaluation ??= self::evaluate(self::read(), time());
        return ['status' => $evaluation['status'], 'checks' => array_map(static fn (array $g): string => $g['status'], $evaluation['groups'])];
    }
}
