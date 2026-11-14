<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Cópias repetidas do Disco Virtual para a lixeira, em lote (ADR 0275). O Disco Virtual (AP-122, ADR 0047) recusa o mesmo
 * arquivo (sha256) só dentro da mesma pasta: a mesma ata vai para Atas e para Outros, o mesmo contrato para Contratos e
 * para Notas fiscais. O inventário (ADR 0274) mostra quantas cópias há; tirar cada uma é abrir arquivo por arquivo.
 *
 * Reusa o que já existe: cada cópia vai para a lixeira pelo VirtualDisk::delete, a mesma exclusão lógica e auditada da
 * tela (o arquivo físico continua guardado; nada é apagado do disco). Fica sempre a cópia mais antiga de cada conteúdo
 * (o menor id). Cópia ligada a outro cadastro (contrato, seguro, manutenção, ocorrência, empenho, cópia de cheque, aviso
 * de entrega, malote, procuração de assembleia ou anexo de lançamento, ADR 0174) não sai: o vínculo apontaria para um
 * arquivo na lixeira. Cópia compartilhada com os moradores (ADR 0302) também não sai: sumiria do portal.
 *
 * - Entram: as cópias não excluídas do condomínio, além da mais antiga de cada conteúdo, ou só os document_ids
 *   informados. Regra pura problem(): arquivo não encontrado no condomínio (ou já na lixeira), sem outra cópia, a cópia
 *   que fica (a mais antiga) ou ligado a outro cadastro. Sem ids, os ligados ficam de fora e a contagem sai em
 *   skipped_linked.
 * - Tudo ou nada, numa transação com os arquivos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _document). dry_run passa por tudo e desfaz (inclusive a auditoria da
 *   exclusão, que vai na mesma transação).
 * - Auditoria: a delete do Disco Virtual e um registro trash_duplicate por cópia (a cópia que fica e o motivo), só
 *   quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, pasta, bytes e o id da cópia
 * que fica: nenhum título, nome de arquivo, caminho ou hash. Até MAX_DOCUMENTS por vez; motivo obrigatório.
 */
final class VirtualDiskDeduplication
{
    public const MAX_DOCUMENTS = 500;
    /** Cadastros que guardam um arquivo do Disco Virtual: tabela => coluna. */
    public const LINKS = ['erp_contracts' => 'document_id', 'erp_insurances' => 'document_id', 'erp_maintenances' => 'document_id',
        'erp_occurrences' => 'document_id', 'erp_commitments' => 'document_id', 'erp_check_copies' => 'document_id', 'erp_delivery_notices' => 'document_id',
        'erp_pouches' => 'document_id', 'erp_assembly_attendance' => 'proxy_document_id', 'erp_entry_attachments' => 'document_id'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private VirtualDisk $disk;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?VirtualDisk $disk = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->disk = $disk ?? new VirtualDisk($this->pdo, $this->scope);
    }

    /**
     * Cópia que fica de cada conteúdo, a mais antiga (regra pura).
     *
     * @param list<array{id:int, sha256:string}> $documents
     * @return array<int, int> id do arquivo => id da cópia que fica (só para conteúdos com mais de um arquivo)
     */
    public static function keepers(array $documents): array
    {
        $first = [];
        $count = [];
        foreach ($documents as $d) {
            $first[$d['sha256']] = min($first[$d['sha256']] ?? PHP_INT_MAX, $d['id']);
            $count[$d['sha256']] = ($count[$d['sha256']] ?? 0) + 1;
        }
        $out = [];
        foreach ($documents as $d) {
            if ($count[$d['sha256']] > 1) {
                $out[$d['id']] = $first[$d['sha256']];
            }
        }
        return $out;
    }

    /** Motivo da recusa (regra pura); null quando a cópia pode ir para a lixeira. */
    public static function problem(?array $row, ?int $keeper, bool $linked): ?string
    {
        return match (true) {
            $row === null => 'Arquivo não encontrado neste condomínio (ou já está na lixeira).',
            $keeper === null => 'O arquivo não tem outra cópia no Disco Virtual.',
            $keeper === (int)$row['id'] => 'Esta é a cópia que fica (a mais antiga): escolha as outras.',
            $linked => 'O arquivo está ligado a outro cadastro (contrato, seguro, lançamento ou outro) ou compartilhado com os moradores: troque ou descompartilhe lá antes.',
            default => null,
        };
    }

    /**
     * Arquivos (dos informados) ligados a algum cadastro.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function linked(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (self::LINKS as $table => $column) {
            $stmt = $this->pdo->prepare("SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IN ({$in})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int)$id] = true;
            }
        }
        // Compartilhada com os moradores (ADR 0302): tirar a cópia a tiraria do portal.
        $stmt = $this->pdo->prepare("SELECT id FROM erp_documents WHERE shared_with_residents = 1 AND id IN ({$in})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function trash(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('document_ids', $input) && $input['document_ids'] !== null) {
            if (!is_array($input['document_ids']) || $input['document_ids'] === []) {
                throw new InvalidArgumentException('Arquivos: informe a lista de ids ou deixe vazio para todas as cópias repetidas.');
            }
            $ids = [];
            foreach ($input['document_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Arquivos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_DOCUMENTS) {
                throw new InvalidArgumentException(sprintf('Até %d arquivos por vez.', self::MAX_DOCUMENTS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_linked' => 0, 'documents' => []];
        $this->pdo->beginTransaction();
        try {
            // Todos os arquivos não excluídos do condomínio, travados: a cópia que fica depende do conjunto inteiro.
            $stmt = $this->pdo->prepare('SELECT id, folder, size_bytes, sha256 FROM erp_documents WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id FOR UPDATE');
            $stmt->execute([(int)$condominium]);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'folder' => (string)$r['folder'], 'size_bytes' => (int)$r['size_bytes'], 'sha256' => (string)$r['sha256']];
            }
            $keepers = self::keepers(array_values($byId));
            $rows = [];
            if ($ids === null) {
                $copies = array_keys(array_filter($keepers, static fn (int $keeper, int $id): bool => $keeper !== $id, ARRAY_FILTER_USE_BOTH));
                $linked = $this->linked($copies);
                foreach ($copies as $id) {
                    if (isset($linked[$id])) {
                        $result['skipped_linked']++;
                    } else {
                        $rows[] = $byId[$id];
                    }
                }
                if (count($rows) > self::MAX_DOCUMENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d cópias a tirar: tire em partes (escolha os ids).', self::MAX_DOCUMENTS));
                }
            } else {
                $linked = $this->linked($ids);
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $problem = self::problem($row, $keepers[$id] ?? null, isset($linked[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_document' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                $this->disk->delete($user, $row['id']);
                $result['documents'][] = ['id' => $row['id'], 'folder' => $row['folder'], 'size_bytes' => $row['size_bytes'], 'kept_id' => $keepers[$row['id']]];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['documents'] as $d) {
                Audit::record('trash_duplicate', 'erp_documents', $d['id'], null, ['kept_id' => $d['kept_id'], 'folder' => $d['folder'], 'reason' => $reason,
                    'batch' => count($result['documents'])]);
            }
        }
        $result['count'] = count($result['documents']);
        $result['groups'] = count(array_unique(array_column($result['documents'], 'kept_id')));
        $result['bytes'] = array_sum(array_column($result['documents'], 'size_bytes'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
