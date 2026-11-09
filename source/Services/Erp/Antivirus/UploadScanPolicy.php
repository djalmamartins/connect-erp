<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

use InvalidArgumentException;
use Source\Support\AppLogger;

/**
 * Política do antivírus no upload (ADR 0098), lida do ambiente aqui (sem passar por Boot/Settings.php):
 *
 * - ERP_AV_MODE=off (padrão, ou variável vazia): não verifica; o arquivo entra com scan_status=unscanned.
 * - ERP_AV_MODE=enforce: verifica antes de guardar. Limpo entra com clean. Infectado é recusado
 *   (InvalidArgumentException, 422). Scanner desligado ou com erro também recusa (UploadScanUnavailable,
 *   503): falha fechada.
 * - Qualquer outro valor conta como enforce: um erro de digitação não desliga a verificação.
 * - ERP_AV_ENDPOINT: endereço do clamd (ClamdScanner). Sem ele, o scanner é o NullScanner.
 */
final class UploadScanPolicy
{
    public const MODE_OFF = 'off';
    public const MODE_ENFORCE = 'enforce';
    public const INFECTED_MESSAGE = 'O arquivo foi recusado pelo antivírus.';
    public const UNAVAILABLE_MESSAGE = 'Não foi possível verificar o arquivo com o antivírus agora. Tente de novo mais tarde.';

    private string $mode;
    private UploadScanner $scanner;

    public function __construct(?string $mode = null, ?UploadScanner $scanner = null)
    {
        $this->mode = self::mode($mode ?? (string)(getenv('ERP_AV_MODE') ?: ''));
        $this->scanner = $scanner ?? self::scannerFromEnvironment();
    }

    /** Modo efetivo (regra pura): vazio ou "off" desliga; o resto liga. */
    public static function mode(string $raw): string
    {
        $raw = strtolower(trim($raw));
        return $raw === '' || $raw === self::MODE_OFF ? self::MODE_OFF : self::MODE_ENFORCE;
    }

    public static function scannerFromEnvironment(): UploadScanner
    {
        $clamd = new ClamdScanner();
        return $clamd->available() ? $clamd : new NullScanner();
    }

    public function currentMode(): string
    {
        return $this->mode;
    }

    /**
     * Verifica o arquivo temporário e devolve o status para gravar, ou recusa.
     *
     * @param array<string, scalar> $context para o log (sem nome de arquivo nem dado pessoal)
     * @throws InvalidArgumentException arquivo infectado (422)
     * @throws UploadScanUnavailable    modo enforce sem scanner ou com erro (503)
     */
    public function check(string $path, array $context = []): string
    {
        if ($this->mode === self::MODE_OFF) {
            return ScanStatus::UNSCANNED;
        }
        $status = $this->scanner->available() ? $this->scanner->scan($path) : ScanStatus::ERROR;
        if ($status === ScanStatus::CLEAN) {
            return ScanStatus::CLEAN;
        }
        if ($status === ScanStatus::INFECTED) {
            self::log('warning', 'Upload recusado pelo antivírus', 'upload_infected', $context);
            throw new InvalidArgumentException(self::INFECTED_MESSAGE);
        }
        self::log('error', 'Antivírus indisponível: upload recusado', 'upload_scan_unavailable', $context);
        throw new UploadScanUnavailable(self::UNAVAILABLE_MESSAGE);
    }

    private static function log(string $level, string $message, string $event, array $context): void
    {
        try {
            AppLogger::log($level, $message, ['event_type' => $event, 'status' => 'open'] + $context, 'erp');
        } catch (\Throwable) {
            // o log não muda a decisão
        }
    }
}
