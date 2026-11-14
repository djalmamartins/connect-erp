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
 * Arquivos do Disco Virtual que ainda têm vínculo, de volta da lixeira, em lote (ADR 0277). A exclusão no Disco Virtual
 * (AP-122, ADR 0047) é lógica (deleted_at e deleted_by), e o relatório de vínculos (ADR 0276) mostra os cadastros que
 * apontam para um arquivo que foi para a lixeira: o contrato, o seguro ou o malote mostra "documento removido". Corrigir
 * é tirar da lixeira arquivo por arquivo, e não há tela para isso.
 *
 * Reusa o que já existe: as tabelas que guardam um arquivo (VirtualDiskDeduplication::LINKS) e a consulta de vínculos do
 * relatório (VirtualDiskLinks::linkCounts). O VirtualDisk não tem restauração; ela é o UPDATE abaixo, o inverso da
 * VirtualDisk::delete (deleted_at e deleted_by voltam a NULL), com auditoria restore em erp_documents. O arquivo físico
 * nunca saiu do disco; nada é apagado nem copiado.
 *
 * - Entram: os arquivos da lixeira do condomínio com algum vínculo, ou só os document_ids informados. Regra pura
 *   problem(): arquivo não encontrado no condomínio, fora da lixeira, sem vínculo ou com outro arquivo igual (mesmo
 *   sha256) fora da lixeira na mesma pasta, o que o envio recusa. Os restaurados do lote contam como fora da lixeira, em
 *   ordem de id: de dois iguais na mesma pasta, volta o mais antigo. Sem ids, os iguais ficam de fora e a contagem sai em
 *   skipped_duplicate.
 * - Tudo ou nada, numa transação com os arquivos do condomínio travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _document). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro restore por arquivo (pasta, vínculos, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, pasta, bytes e vínculos:
 * nenhum título, nome de arquivo, caminho ou hash. Até MAX_DOCUMENTS por vez; motivo obrigatório; nenhum aviso é enviado.
 */
final class VirtualDiskRestore
{
    public const MAX_DOCUMENTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;
    private VirtualDiskLinks $links;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?VirtualDiskLinks $links = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->links = $links ?? new VirtualDiskLinks($this->pdo, $this->scope);
    }

    /** Motivo da recusa (regra pura); null quando o arquivo pode sair da lixeira. */
    public static function problem(?array $row, int $links, bool $clash): ?string
    {
        return match (true) {
            $row === null => 'Arquivo não encontrado neste condomínio.',
            !$row['deleted'] => 'O arquivo não está na lixeira.',
            $links < 1 => 'O arquivo não está ligado a nenhum cadastro: só voltam os arquivos com vínculo.',
            $clash => 'Já existe outro arquivo igual nesta pasta, fora da lixeira: ligue o cadastro a ele em vez de restaurar este.',
            default => null,
        };
    }

    /**
     * Vínculos por arquivo, somando todos os cadastros (regra pura).
     *
     * @param array<string, array<int, int>> $links cadastro => id do arquivo => vínculos
     * @return array<int, int>
     */
    public static function totals(array $links): array
    {
        $out = [];
        foreach ($links as $byDocument) {
            foreach ($byDocument as $id => $count) {
                $out[$id] = ($out[$id] ?? 0) + $count;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function restore(User $user, array $input): array
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
                throw new InvalidArgumentException('Arquivos: informe a lista de ids ou deixe vazio para todos os arquivos da lixeira com vínculo.');
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
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_duplicate' => 0, 'documents' => []];
        $this->pdo->beginTransaction();
        try {
            // Todos os arquivos do condomínio, travados: o arquivo igual fora da lixeira pode ser qualquer um deles.
            $stmt = $this->pdo->prepare('SELECT id, folder, size_bytes, sha256, deleted_at FROM erp_documents WHERE condominium_id = ? ORDER BY id FOR UPDATE');
            $stmt->execute([(int)$condominium]);
            $byId = [];
            $active = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $row = ['id' => (int)$r['id'], 'folder' => (string)$r['folder'], 'size_bytes' => (int)$r['size_bytes'], 'content' => $r['folder'] . "\0" . $r['sha256'],
                    'deleted' => $r['deleted_at'] !== null];
                $byId[$row['id']] = $row;
                if (!$row['deleted']) {
                    $active[$row['content']] = true;
                }
            }
            $links = self::totals($this->links->linkCounts((int)$condominium));
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $row) {
                    if (!$row['deleted'] || ($links[$row['id']] ?? 0) < 1) {
                        continue;
                    }
                    if (isset($active[$row['content']])) {
                        $result['skipped_duplicate']++;
                        continue;
                    }
                    $active[$row['content']] = true;
                    $rows[] = $row;
                }
                if (count($rows) > self::MAX_DOCUMENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d arquivos a restaurar: restaure em partes (escolha os ids).', self::MAX_DOCUMENTS));
                }
            } else {
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $problem = self::problem($row, $links[$id] ?? 0, $row !== null && isset($active[$row['content']]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_document' => (string)$id]);
                    }
                    $active[$row['content']] = true;
                    $rows[] = $row;
                }
            }
            $update = $this->pdo->prepare('UPDATE erp_documents SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND condominium_id = ? AND deleted_at IS NOT NULL');
            foreach ($rows as $row) {
                $update->execute([$row['id'], (int)$condominium]);
                $result['documents'][] = ['id' => $row['id'], 'folder' => $row['folder'], 'size_bytes' => $row['size_bytes'], 'links' => $links[$row['id']]];
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
                Audit::record('restore', 'erp_documents', $d['id'], ['deleted' => true], ['deleted' => false, 'folder' => $d['folder'], 'links' => $d['links'],
                    'reason' => $reason, 'batch' => count($result['documents'])]);
            }
        }
        $result['count'] = count($result['documents']);
        $result['links'] = array_sum(array_column($result['documents'], 'links'));
        $result['bytes'] = array_sum(array_column($result['documents'], 'size_bytes'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
