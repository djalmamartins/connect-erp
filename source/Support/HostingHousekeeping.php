<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Faxina diária da hospedagem (ADR 0336), regras puras:
 *  - logPlan(): os logs diários do AppLogger (storage/logs/movesos-AAAA-MM-DD.log) são compactados (.gz) depois de
 *    COMPRESS_DAYS dias e apagados depois de KEEP_DAYS dias (6 meses: o Marco Civil, art. 15, pede guardar os registros de
 *    acesso a aplicação por 6 meses). A data vem do nome do arquivo, não do relógio do disco. O log de hoje nunca é tocado.
 *  - diskProblem(): espaço usado pela instalação perto do limite do plano (DISK_WARN do total).
 *  - certProblem(): certificado HTTPS do site vencendo em menos de CERT_WARN_DAYS dias.
 */
final class HostingHousekeeping
{
    public const COMPRESS_DAYS = 2;
    public const KEEP_DAYS = 183;
    public const DISK_WARN = 0.85;
    public const CERT_WARN_DAYS = 15;
    /** Cópia local do OneDrive (ADR 0375): sai depois de 7 dias sem uso; envio interrompido (.part), depois de 1 dia. */
    public const CACHE_DAYS = 7;

    /**
     * @param list<string> $names nomes dos arquivos de storage/logs
     * @return array{compress:list<string>, delete:list<string>}
     */
    /**
     * Arquivos do cache do OneDrive a apagar (ADR 0375). O path() do armazenamento renova a data a cada uso.
     *
     * @param array<string, int> $files caminho => data de modificação
     * @return list<string>
     */
    public static function cachePlan(array $files, int $now): array
    {
        $delete = [];
        foreach ($files as $path => $modified) {
            $limit = str_ends_with($path, '.part') ? 86400 : self::CACHE_DAYS * 86400;
            if ($now - $modified > $limit) {
                $delete[] = $path;
            }
        }
        sort($delete);
        return $delete;
    }

    public static function logPlan(array $names, string $today): array
    {
        $plan = ['compress' => [], 'delete' => []];
        $now = strtotime($today . ' 00:00:00');
        foreach ($names as $name) {
            if (!preg_match('/^movesos-(\d{4}-\d{2}-\d{2})\.log(\.gz)?$/', $name, $m) || ($day = strtotime($m[1] . ' 00:00:00')) === false) {
                continue;
            }
            $age = intdiv($now - $day, 86400);
            if ($age > self::KEEP_DAYS) {
                $plan['delete'][] = $name;
            } elseif ($age >= self::COMPRESS_DAYS && empty($m[2])) {
                $plan['compress'][] = $name;
            }
        }
        sort($plan['compress']);
        sort($plan['delete']);
        return $plan;
    }

    public static function diskProblem(int $usedKb, float $limitGb): ?string
    {
        if ($limitGb <= 0) {
            return null;
        }
        $ratio = $usedKb / ($limitGb * 1024 * 1024);
        return $ratio >= self::DISK_WARN
            ? sprintf('O sistema já usa %s GB dos %s GB do plano (%d%%). Veja documentos, fotos e .local/backups.', number_format($usedKb / 1048576, 1, ',', '.'), rtrim(rtrim(number_format($limitGb, 1, ',', ''), '0'), ','), (int)round($ratio * 100))
            : null;
    }

    public static function certProblem(?int $daysLeft, string $host): ?string
    {
        if ($daysLeft === null) {
            return null;
        }
        if ($daysLeft < 0) {
            return sprintf('O certificado HTTPS de %s venceu. Renove no hPanel (SSL).', $host);
        }
        return $daysLeft < self::CERT_WARN_DAYS ? sprintf('O certificado HTTPS de %s vence em %d dia(s). Confira a renovação automática no hPanel (SSL).', $host, $daysLeft) : null;
    }

    /** Dias até o certificado do host vencer, ou null se não deu para ler (sem rede, sem HTTPS). */
    public static function certDaysLeft(string $host, int $now, int $timeout = 5): ?int
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client('ssl://' . $host . ':443', $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return null;
        }
        $cert = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $cert !== null ? openssl_x509_parse($cert) : false;
        if (!is_array($info) || !isset($info['validTo_time_t'])) {
            return null;
        }
        return (int)floor(((int)$info['validTo_time_t'] - $now) / 86400);
    }
}
