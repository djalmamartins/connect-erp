<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

use PDO;
use Source\Core\Connect;
use Source\Services\Erp\VirtualDisk;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Reverificação dos arquivos do Disco Virtual quando o antivírus é ligado depois (ADR 0161).
 *
 * - Pega até BATCH arquivos com scan_status 'unscanned' ou 'error' e scanned_at nulo ou com mais de
 *   RETRY_AFTER segundos, do mais antigo para o mais novo.
 * - Passa cada um pelo scanner configurado e grava scan_status e scanned_at. O UPDATE é condicionado ao status
 *   antigo, então uma marcação feita no meio do caminho não é sobrescrita.
 * - ERP_AV_MODE=off: não faz nada (status 'off').
 * - Scanner desligado: não marca nada (status 'unavailable'). Se o scanner falhar no meio do lote, o lote para
 *   ali, sem marcar o arquivo da falha (status 'interrupted'). Arquivo que sumiu da pasta vira 'error'.
 * - Trava: GET_LOCK no MySQL; em outro banco (testes), flock num arquivo. Já rodando = status 'locked'.
 * - Auditoria: um resumo por lote (quantos clean, infected e error). Nunca leva nome, caminho nem conteúdo.
 */
final class DocumentRescan
{
    public const BATCH = 50;
    public const RETRY_AFTER = 86400;
    public const LOCK_NAME = 'erp_document_rescan';

    private ?PDO $pdo;
    private string $mode;
    private UploadScanner $scanner;
    private VirtualDisk $disk;
    /** @var callable(array<string,int>):void */
    private $audit;
    private string $lockFile;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?PDO $pdo = null, ?string $mode = null, ?UploadScanner $scanner = null, ?string $root = null,
        ?callable $audit = null, ?string $lockFile = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->mode = UploadScanPolicy::mode($mode ?? (string)(getenv('ERP_AV_MODE') ?: ''));
        $this->scanner = $scanner ?? UploadScanPolicy::scannerFromEnvironment();
        $this->disk = new VirtualDisk($this->pdo, null, $root);
        $this->audit = $audit ?? static function (array $summary): void {
            Audit::record('update', 'erp_documents_rescan', null, null, $summary);
        };
        $this->lockFile = $lockFile ?? sys_get_temp_dir() . '/erp-document-rescan.lock';
    }

    /**
     * Roda um lote.
     *
     * @return array{status:string, checked:int, clean:int, infected:int, error:int}
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $summary = ['status' => 'done', 'checked' => 0, 'clean' => 0, 'infected' => 0, 'error' => 0];
        if ($this->mode === UploadScanPolicy::MODE_OFF) {
            return ['status' => 'off'] + $summary;
        }
        if (!$this->scanner->available()) {
            return ['status' => 'unavailable'] + $summary;
        }
        if (!$this->lock()) {
            return ['status' => 'locked'] + $summary;
        }
        try {
            foreach ($this->pending($now) as $row) {
                $path = $this->disk->absolute((string)$row['path']);
                $result = $path === null ? ScanStatus::ERROR : $this->scanner->scan($path);
                if ($path !== null && !in_array($result, [ScanStatus::CLEAN, ScanStatus::INFECTED], true)) {
                    $summary['status'] = 'interrupted';   // o scanner falhou: para sem marcar este arquivo
                    break;
                }
                $this->mark((int)$row['id'], (string)$row['scan_status'], $result, $now);
                $summary['checked']++;
                $summary[$result]++;
                if ($result === ScanStatus::INFECTED) {
                    self::log('warning', 'Arquivo do Disco Virtual marcado como infectado na reverificação', ['event_type' => 'document_rescan_infected', 'document_id' => (int)$row['id']]);
                }
            }
        } finally {
            $this->unlock();
        }
        if ($summary['checked'] > 0) {
            ($this->audit)(['clean' => $summary['clean'], 'infected' => $summary['infected'], 'error' => $summary['error']]);
        }
        return $summary;
    }

    /** @return list<array{id:int|string, path:string, scan_status:string}> */
    private function pending(int $now): array
    {
        $stmt = $this->pdo->prepare("SELECT id, path, scan_status FROM erp_documents
            WHERE scan_status IN ('unscanned','error') AND (scanned_at IS NULL OR scanned_at < :cut) ORDER BY id LIMIT " . self::BATCH);
        $stmt->execute(['cut' => date('Y-m-d H:i:s', $now - self::RETRY_AFTER)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mark(int $id, string $from, string $status, int $now): void
    {
        $this->pdo->prepare('UPDATE erp_documents SET scan_status = :s, scanned_at = :t WHERE id = :id AND scan_status = :from')
            ->execute(['s' => $status, 't' => date('Y-m-d H:i:s', $now), 'id' => $id, 'from' => $from]);
    }

    private function lock(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return (int)$this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() === 1;
        }
        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }

    private function unlock(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
            return;
        }
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private static function log(string $level, string $message, array $context): void
    {
        try {
            AppLogger::log($level, $message, $context + ['status' => 'open'], 'erp');
        } catch (\Throwable) {
            // o log não muda o resultado
        }
    }
}
