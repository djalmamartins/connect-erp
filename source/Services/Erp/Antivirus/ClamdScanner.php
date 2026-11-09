<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

/**
 * Cliente do clamd pelo comando INSTREAM (ADR 0098).
 *
 * - Endereço em ERP_AV_ENDPOINT: "unix:///caminho/clamd.sock", "tcp://host:porta" ou "host:porta".
 *   Sem valor, o adaptador fica desligado (available() = false) e scan() devolve error.
 * - Timeout curto: CONNECT_TIMEOUT para conectar e IO_TIMEOUT para cada leitura e escrita.
 * - Protocolo: "zINSTREAM\0", blocos de até CHUNK bytes com o tamanho em 4 bytes big-endian, um bloco de
 *   tamanho zero no fim, e a resposta "stream: OK" ou "stream: <assinatura> FOUND", terminada em \0.
 * - Nunca lança exceção nem registra o nome do arquivo: falha de conexão, timeout ou resposta estranha
 *   viram ScanStatus::ERROR.
 */
final class ClamdScanner implements UploadScanner
{
    public const CONNECT_TIMEOUT = 2;
    public const IO_TIMEOUT = 10;
    public const CHUNK = 65536;
    /** ADR 0160: prazo total da verificação (conectar, enviar e ler), além do timeout de cada operação. */
    public const TOTAL_TIMEOUT = 30;
    /**
     * ADR 0160: o maior arquivo que vai para o INSTREAM. É o StreamMaxLength padrão do clamd (25 MB), acima
     * do limite do Disco Virtual (20 MB). Maior que isso = error sem conectar (no modo enforce, recusa).
     */
    public const MAX_STREAM_BYTES = 26214400;
    private const MAX_REPLY = 1024;

    private ?string $endpoint;
    /** @var callable|null Testes: recebe o endereço e devolve o "socket" falso (resource) ou null. */
    private $connector;

    public function __construct(?string $endpoint = null, ?callable $connector = null)
    {
        $endpoint ??= (string)(getenv('ERP_AV_ENDPOINT') ?: '');
        $this->endpoint = trim($endpoint) !== '' ? trim($endpoint) : null;
        $this->connector = $connector;
    }

    public function available(): bool
    {
        return $this->endpoint !== null && ($this->connector !== null || self::address($this->endpoint) !== null);
    }

    /** Endereço para stream_socket_client (regra pura), ou null se o formato não for aceito. */
    public static function address(string $endpoint): ?string
    {
        $endpoint = trim($endpoint);
        if (preg_match('~^unix:///[^\0\s]+$~', $endpoint) === 1) {
            return $endpoint;
        }
        if (preg_match('~^(?:tcp://)?([a-z0-9.\-]+|\[[0-9a-f:]+\]):(\d{1,5})$~i', $endpoint, $m) === 1 && (int)$m[2] >= 1 && (int)$m[2] <= 65535) {
            return 'tcp://' . $m[1] . ':' . $m[2];
        }
        return null;
    }

    /**
     * Lê a resposta do clamd (regra pura). ADR 0160: só vale a resposta completa, terminada em \0 (o comando
     * "z"); cortada, vazia, com mais de uma linha ou fora do formato = error, que no modo enforce recusa.
     */
    public static function interpret(string $reply): string
    {
        $end = strpos($reply, "\0");
        if ($end === false) {
            return ScanStatus::ERROR;
        }
        $reply = substr($reply, 0, $end);
        if (preg_match('/[\r\n]/', $reply) === 1) {
            return ScanStatus::ERROR;
        }
        if (preg_match('/^(?:stream|\d+): OK$/', $reply) === 1) {
            return ScanStatus::CLEAN;
        }
        if (preg_match('/^(?:stream|\d+): .+ FOUND$/', $reply) === 1) {
            return ScanStatus::INFECTED;
        }
        return ScanStatus::ERROR;
    }

    public function scan(string $path): string
    {
        if (!$this->available() || !is_file($path) || !is_readable($path)) {
            return ScanStatus::ERROR;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_STREAM_BYTES) {
            return ScanStatus::ERROR;   // ADR 0160: o clamd cortaria o stream; nem conecta
        }
        $deadline = microtime(true) + self::TOTAL_TIMEOUT;
        $socket = $this->open();
        if ($socket === null) {
            return ScanStatus::ERROR;
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            @fclose($socket);
            return ScanStatus::ERROR;
        }
        try {
            @stream_set_timeout($socket, self::IO_TIMEOUT);
            if (!self::write($socket, "zINSTREAM\0", $deadline)) {
                return ScanStatus::ERROR;
            }
            $sent = 0;
            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    return ScanStatus::ERROR;
                }
                $sent += strlen($chunk);
                if ($sent > self::MAX_STREAM_BYTES) {
                    return ScanStatus::ERROR;   // o arquivo cresceu depois do filesize()
                }
                if ($chunk !== '' && !self::write($socket, pack('N', strlen($chunk)) . $chunk, $deadline)) {
                    return ScanStatus::ERROR;
                }
            }
            if (!self::write($socket, pack('N', 0), $deadline)) {
                return ScanStatus::ERROR;
            }
            return self::interpret(self::reply($socket, $deadline));
        } catch (\Throwable) {
            return ScanStatus::ERROR;
        } finally {
            @fclose($file);
            @fclose($socket);
        }
    }

    /** @return resource|null */
    private function open()
    {
        if ($this->connector !== null) {
            $socket = ($this->connector)((string)$this->endpoint);
            return is_resource($socket) ? $socket : null;
        }
        $address = self::address((string)$this->endpoint);
        if ($address === null) {
            return null;
        }
        $socket = @stream_socket_client($address, $errno, $errstr, self::CONNECT_TIMEOUT);
        return is_resource($socket) ? $socket : null;
    }

    /** Lê até o \0 final, o fim da conexão ou o timeout (o que vier antes). @param resource $socket */
    private static function reply($socket, float $deadline): string
    {
        $reply = '';
        while (strlen($reply) < self::MAX_REPLY && !str_contains($reply, "\0")) {
            if (microtime(true) > $deadline) {
                break;   // ADR 0160: prazo total; resposta incompleta vira error em interpret()
            }
            $part = @fread($socket, 256);
            if ($part === false || $part === '') {
                break;   // timeout, conexão fechada ou erro: o que chegou até aqui é interpretado
            }
            $reply .= $part;
        }
        return $reply;
    }

    /** @param resource $socket */
    private static function write($socket, string $data, float $deadline): bool
    {
        $total = strlen($data);
        $sent = 0;
        while ($sent < $total) {
            if (microtime(true) > $deadline) {
                return false;   // ADR 0160: prazo total
            }
            $written = @fwrite($socket, substr($data, $sent));
            if ($written === false || $written === 0) {
                return false;   // erro ou timeout na escrita
            }
            $sent += $written;
        }
        return true;
    }
}
