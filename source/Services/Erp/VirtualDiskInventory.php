<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Antivirus\ScanStatus;

/**
 * Inventário do Disco Virtual do condomínio (ADR 0274), somente leitura. O Disco Virtual (AP-122, ADR 0047) lista arquivo
 * por arquivo numa pasta; a tela de integrações (ADR 0091) conta o antivírus só no total do escopo. Ninguém vê, de uma vez,
 * quantos arquivos e quanto espaço cada pasta do condomínio ocupa, quantos passaram ou não pelo antivírus (ADR 0098),
 * quantos foram enviados nos últimos meses, quantas cópias do mesmo arquivo estão em pastas diferentes (o mesmo conteúdo
 * só é recusado dentro da mesma pasta) e quanto ficou na lixeira (a exclusão é lógica: o arquivo continua guardado).
 *
 * Reusa o que já existe: as pastas (VirtualDisk::FOLDERS), as situações do antivírus (ScanStatus::ALL: clean,
 * infected, unscanned, error; situação desconhecida conta como error) e a identidade do conteúdo pelo sha256, a mesma da
 * recusa de duplicado.
 *
 * Regra pura ageBucket() e summarize(), por arquivo do condomínio:
 * - rows: uma linha por pasta, sempre as cinco, com documents, bytes e o antivírus (as situações de ScanStatus::ALL,
 *   somando documents), só dos não excluídos; totals soma as linhas;
 * - by_age: os não excluídos pelo envio (ate_90, de_91_a_365 e mais_de_365 dias), somando totals.documents;
 * - duplicates: conteúdos (sha256) com mais de um arquivo não excluído: groups, extra_copies (as cópias além da primeira)
 *   e extra_bytes;
 * - trash: os excluídos (documents e bytes).
 *
 * Não grava, não lê o arquivo e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied,
 * 404). Saem só as pastas e as contagens: nenhum título, nome de arquivo, caminho, hash ou quem enviou.
 */
final class VirtualDiskInventory
{
    public const MAX_ROWS = 100000;
    public const SCAN = ScanStatus::ALL;
    public const AGES = ['ate_90' => 90, 'de_91_a_365' => 365, 'mais_de_365' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de idade pelo dia do envio (regra pura). Envio no futuro (relógio) conta como recente. */
    public static function ageBucket(string $createdAt, string $today): string
    {
        $sent = new DateTimeImmutable(substr($createdAt, 0, 10));
        $now = new DateTimeImmutable($today);
        $days = $sent > $now ? 0 : (int)$sent->diff($now)->days;
        return $days <= 90 ? 'ate_90' : ($days <= 365 ? 'de_91_a_365' : 'mais_de_365');
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{folder:string, size_bytes:int, sha256:string, scan_status:string, created_at:string, deleted:bool}> $documents
     */
    public static function summarize(array $documents, string $today): array
    {
        $zero = ['documents' => 0, 'bytes' => 0] + array_fill_keys(self::SCAN, 0);
        $counts = array_fill_keys(array_keys(VirtualDisk::FOLDERS), $zero);
        $ages = array_fill_keys(array_keys(self::AGES), 0);
        $trash = ['documents' => 0, 'bytes' => 0];
        $byHash = [];
        foreach ($documents as $d) {
            if ($d['deleted']) {
                $trash['documents']++;
                $trash['bytes'] += $d['size_bytes'];
                continue;
            }
            if (!isset($counts[$d['folder']])) {
                continue;
            }
            $counts[$d['folder']]['documents']++;
            $counts[$d['folder']]['bytes'] += $d['size_bytes'];
            $counts[$d['folder']][in_array($d['scan_status'], self::SCAN, true) ? $d['scan_status'] : ScanStatus::ERROR]++;
            $ages[self::ageBucket($d['created_at'], $today)]++;
            $byHash[$d['sha256']][] = $d['size_bytes'];
        }
        $rows = [];
        $totals = $zero;
        foreach (VirtualDisk::FOLDERS as $folder => $label) {
            $rows[] = ['folder' => $folder, 'folder_label' => $label] + $counts[$folder];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$folder][$key];
            }
        }
        $duplicates = ['groups' => 0, 'extra_copies' => 0, 'extra_bytes' => 0];
        foreach ($byHash as $sizes) {
            if (count($sizes) > 1) {
                $duplicates['groups']++;
                $duplicates['extra_copies'] += count($sizes) - 1;
                $duplicates['extra_bytes'] += array_sum($sizes) - $sizes[0];
            }
        }
        return ['rows' => $rows, 'totals' => $totals, 'by_age' => $ages, 'duplicates' => $duplicates, 'trash' => $trash];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT folder, size_bytes, sha256, scan_status, created_at, deleted_at FROM erp_documents WHERE condominium_id = ?
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d arquivos no Disco Virtual: fale com o suporte.', self::MAX_ROWS));
        }
        $today = date('Y-m-d');
        return ['condominium_id' => (int)$id, 'on' => $today] + self::summarize(array_map(static fn (array $r): array => ['folder' => (string)$r['folder'],
            'size_bytes' => (int)$r['size_bytes'], 'sha256' => (string)$r['sha256'], 'scan_status' => (string)$r['scan_status'],
            'created_at' => (string)$r['created_at'], 'deleted' => $r['deleted_at'] !== null], $rows), $today);
    }
}
