<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Vínculos dos arquivos do Disco Virtual por condomínio (ADR 0276), somente leitura. O Disco Virtual (AP-122, ADR 0047)
 * guarda os arquivos, e vários cadastros apontam para eles (contrato, seguro, manutenção, ocorrência, empenho, cópia de
 * cheque, aviso de entrega, malote, procuração de assembleia, anexo de lançamento). O inventário (ADR 0274) conta arquivos
 * e espaço, e o lote de cópias (ADR 0275) não tira arquivo ligado. Ninguém vê, de uma vez, quantos arquivos de cada pasta
 * estão ligados a algum cadastro e quantos estão soltos, quantos arquivos cada cadastro usa e, principalmente, quantos
 * vínculos apontam para um arquivo que já foi para a lixeira: o cadastro mostra "documento removido" e precisa ser
 * corrigido.
 *
 * Reusa o que já existe: as pastas (VirtualDisk::FOLDERS) e as tabelas que guardam um arquivo do Disco Virtual
 * (VirtualDiskDeduplication::LINKS, a mesma lista que protege as cópias ligadas). Os vínculos são contados pelo arquivo do
 * condomínio para o qual apontam.
 *
 * Regra pura summarize():
 * - folders: uma linha por pasta, sempre todas as de VirtualDisk::FOLDERS, com documents (não excluídos), linked (com algum
 *   vínculo) e loose (sem vínculo), somando documents; totals soma as linhas;
 * - registries: uma linha por cadastro, sempre todos os de LINKS, com documents (arquivos não excluídos que ele usa),
 *   links (vínculos para arquivos não excluídos) e broken_links (vínculos para arquivos na lixeira);
 * - broken: links (a soma dos broken_links), documents (arquivos distintos na lixeira com vínculo) e document_ids (até
 *   MAX_IDS, com truncated quando passa), para a administradora abrir e corrigir.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * pastas, os cadastros, as contagens e ids: nenhum título, nome de arquivo, caminho ou hash.
 */
final class VirtualDiskLinks
{
    public const MAX_ROWS = 100000;
    public const MAX_IDS = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, folder:string, deleted:bool}> $documents arquivos do condomínio, inclusive os da lixeira
     * @param array<string, array<int, int>> $links cadastro (tabela de LINKS) => id do arquivo => vínculos
     */
    public static function summarize(array $documents, array $links): array
    {
        $byId = [];
        foreach ($documents as $d) {
            $byId[$d['id']] = $d;
        }
        $linkedIds = [];
        $brokenIds = [];
        $registries = [];
        foreach (array_keys(VirtualDiskDeduplication::LINKS) as $registry) {
            $row = ['registry' => $registry, 'documents' => 0, 'links' => 0, 'broken_links' => 0];
            foreach ($links[$registry] ?? [] as $id => $count) {
                if (!isset($byId[$id]) || $count < 1) {
                    continue;
                }
                if ($byId[$id]['deleted']) {
                    $row['broken_links'] += $count;
                    $brokenIds[$id] = true;
                } else {
                    $row['documents']++;
                    $row['links'] += $count;
                    $linkedIds[$id] = true;
                }
            }
            $registries[] = $row;
        }
        $zero = ['documents' => 0, 'linked' => 0, 'loose' => 0];
        $counts = array_fill_keys(array_keys(VirtualDisk::FOLDERS), $zero);
        foreach ($documents as $d) {
            if ($d['deleted'] || !isset($counts[$d['folder']])) {
                continue;
            }
            $counts[$d['folder']]['documents']++;
            $counts[$d['folder']][isset($linkedIds[$d['id']]) ? 'linked' : 'loose']++;
        }
        $folders = [];
        $totals = $zero;
        foreach (VirtualDisk::FOLDERS as $folder => $label) {
            $folders[] = ['folder' => $folder, 'folder_label' => $label] + $counts[$folder];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$folder][$key];
            }
        }
        $ids = array_keys($brokenIds);
        sort($ids);
        return ['folders' => $folders, 'totals' => $totals, 'registries' => $registries,
            'broken' => ['links' => array_sum(array_column($registries, 'broken_links')), 'documents' => count($ids),
                'document_ids' => array_slice($ids, 0, self::MAX_IDS), 'truncated' => count($ids) > self::MAX_IDS]];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, folder, deleted_at FROM erp_documents WHERE condominium_id = ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d arquivos no Disco Virtual: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'folder' => (string)$r['folder'],
            'deleted' => $r['deleted_at'] !== null], $rows), $this->linkCounts((int)$id));
    }

    /**
     * Vínculos que apontam para arquivos do condomínio, inclusive os da lixeira (o escopo vem do arquivo). Não confere o
     * escopo: quem chama já passou pelo CondominiumScope::assertAccess (o build acima e o lote de restauração, ADR 0277).
     *
     * @return array<string, array<int, int>> cadastro (tabela de LINKS) => id do arquivo => vínculos
     */
    public function linkCounts(int $condominiumId): array
    {
        $links = [];
        foreach (VirtualDiskDeduplication::LINKS as $table => $column) {
            $stmt = $this->pdo->prepare("SELECT l.{$column} AS document_id, COUNT(*) AS n FROM {$table} l JOIN erp_documents d ON d.id = l.{$column}
                WHERE d.condominium_id = ? GROUP BY l.{$column}");
            $stmt->execute([$condominiumId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $links[$table][(int)$r['document_id']] = (int)$r['n'];
            }
        }
        return $links;
    }
}
