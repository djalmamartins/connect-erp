<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Anexos dos lançamentos (ADR 0174): nota fiscal, comprovante de pagamento, recibo ou outro documento.
 *
 * - O arquivo vai para o Disco Virtual (ADR 0047) pelas mesmas regras de envio (tipo pelo conteúdo, 20 MB,
 *   pasta privada, antivírus do ADR 0098): nota fiscal na pasta "Notas fiscais", comprovante e recibo em
 *   "Comprovantes", o resto em "Outros". Esta classe guarda só o vínculo com o lançamento.
 * - O mesmo arquivo (sha256) já enviado na pasta do condomínio é reaproveitado: uma nota com duas parcelas
 *   serve aos dois lançamentos. O mesmo arquivo no mesmo lançamento é recusado.
 * - Anexar vale até em competência fechada (o comprovante costuma chegar depois e não muda valor); remover
 *   não, para o livro caixa já entregue continuar batendo com o sistema.
 * - Escopo pelo condomínio do lançamento (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class EntryAttachments
{
    public const KINDS = ['nota_fiscal' => 'Nota fiscal', 'comprovante' => 'Comprovante de pagamento', 'recibo' => 'Recibo', 'outro' => 'Outro documento'];
    /** Pasta do Disco Virtual de cada tipo. */
    public const FOLDER = ['nota_fiscal' => 'notas_fiscais', 'comprovante' => 'comprovantes', 'recibo' => 'comprovantes', 'outro' => 'outros'];
    public const MAX_PER_ENTRY = 20;

    private PDO $pdo;
    private CondominiumScope $scope;
    private VirtualDisk $disk;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?VirtualDisk $disk = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->disk = $disk ?? new VirtualDisk($this->pdo, $this->scope);
    }

    /** Tipo informado, validado (regra pura). */
    public static function kind(mixed $value): string
    {
        if (!is_string($value) || !isset(self::KINDS[$value])) {
            throw new InvalidArgumentException('Escolha o tipo do anexo: nota fiscal, comprovante de pagamento, recibo ou outro documento.');
        }
        return $value;
    }

    /** @return list<array<string, mixed>> anexos válidos do lançamento, na ordem de envio */
    public function list(User $user, int $entryId): array
    {
        $entry = $this->entry($user, $entryId);
        return $this->forEntries((int)$entry['condominium_id'], [$entryId])[$entryId] ?? [];
    }

    /**
     * Anexos válidos de vários lançamentos de um condomínio (o livro caixa usa, com $withPath para ler os
     * arquivos). Sem conferir escopo: quem chama já conferiu o condomínio.
     *
     * @param list<int> $entryIds
     * @return array<int, list<array<string, mixed>>> por id do lançamento
     */
    public function forEntries(int $condominiumId, array $entryIds, bool $withPath = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->pdo->prepare('SELECT a.id, a.entry_id, a.kind, a.document_id, a.created_at, d.title, d.original_name, d.mime, d.size_bytes, d.sha256,
                    d.scan_status, d.path
                FROM erp_entry_attachments a JOIN erp_documents d ON d.id = a.document_id
                WHERE a.condominium_id = :c AND a.deleted_at IS NULL AND d.deleted_at IS NULL AND a.entry_id IN (' . implode(',', $chunk) . ')
                ORDER BY a.entry_id, FIELD(a.kind, \'nota_fiscal\', \'comprovante\', \'recibo\', \'outro\'), a.id');
            $stmt->execute(['c' => $condominiumId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['entry_id']][] = self::present($row, $withPath);
            }
        }
        return $out;
    }

    /**
     * @param array{name?:string, tmp_name?:string, error?:int} $upload item de $_FILES
     */
    public function attach(User $user, int $entryId, array $upload, mixed $kind, mixed $title = null): array
    {
        $kind = self::kind($kind);
        $entry = $this->entry($user, $entryId);
        $condominium = (int)$entry['condominium_id'];
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM erp_entry_attachments WHERE entry_id = :e AND deleted_at IS NULL');
        $count->execute(['e' => $entryId]);
        if ((int)$count->fetchColumn() >= self::MAX_PER_ENTRY) {
            throw new InvalidArgumentException(sprintf('O lançamento já tem %d anexos, o máximo.', self::MAX_PER_ENTRY));
        }
        $folder = self::FOLDER[$kind];
        $title = is_string($title) && trim($title) !== '' ? $title : self::KINDS[$kind] . ' · ' . (string)$entry['description'];

        // O mesmo arquivo já na pasta do condomínio: reaproveita (sem guardar outra cópia).
        $temporary = (string)($upload['tmp_name'] ?? '');
        $documentId = null;
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $temporary !== '' && is_file($temporary)) {
            $dup = $this->pdo->prepare('SELECT id FROM erp_documents WHERE condominium_id = :c AND folder = :f AND sha256 = :h AND deleted_at IS NULL LIMIT 1');
            $dup->execute(['c' => $condominium, 'f' => $folder, 'h' => hash_file('sha256', $temporary)]);
            $found = $dup->fetchColumn();
            $documentId = $found === false ? null : (int)$found;
        }
        if ($documentId !== null) {
            $same = $this->pdo->prepare('SELECT 1 FROM erp_entry_attachments WHERE entry_id = :e AND document_id = :d AND deleted_at IS NULL');
            $same->execute(['e' => $entryId, 'd' => $documentId]);
            if ($same->fetchColumn()) {
                throw new DomainException('Este arquivo já está anexado a este lançamento.');
            }
        } else {
            $documentId = (int)$this->disk->upload($user, $upload, ['condominium_id' => $condominium, 'folder' => $folder, 'title' => $title])['id'];
        }

        $this->pdo->prepare('INSERT INTO erp_entry_attachments (condominium_id, entry_id, document_id, kind, created_by) VALUES (:c, :e, :d, :k, :u)')
            ->execute(['c' => $condominium, 'e' => $entryId, 'd' => $documentId, 'k' => $kind, 'u' => (int)$user->id]);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_entry_attachments', $id, null, ['entry_id' => $entryId, 'document_id' => $documentId, 'kind' => $kind]);
        return $this->attachment($user, $entryId, $id);
    }

    /** Remove o vínculo (exclusão lógica). Competência fechada trava. O arquivo continua no Disco Virtual. */
    public function remove(User $user, int $entryId, int $attachmentId): void
    {
        $entry = $this->entry($user, $entryId);
        $row = $this->row($entryId, $attachmentId);
        if ((new Registry\RegistryService($this->pdo, $this->scope))->isClosed($entry['condominium_id'], $entry['competence'])) {
            throw new DomainException(sprintf('A competência %s está fechada. Reabra o fechamento mensal para remover o anexo.', $entry['competence']));
        }
        $update = $this->pdo->prepare('UPDATE erp_entry_attachments SET deleted_at = NOW(), deleted_by = :u WHERE id = :id AND deleted_at IS NULL');
        $update->execute(['u' => (int)$user->id, 'id' => $attachmentId]);
        Audit::record('delete', 'erp_entry_attachments', $attachmentId, ['entry_id' => $entryId, 'document_id' => (int)$row['document_id'], 'kind' => $row['kind']], null);
    }

    /** Um anexo do lançamento. */
    public function attachment(User $user, int $entryId, int $attachmentId): array
    {
        $entry = $this->entry($user, $entryId);
        $this->row($entryId, $attachmentId);
        foreach ($this->forEntries((int)$entry['condominium_id'], [$entryId])[$entryId] ?? [] as $item) {
            if ($item['id'] === $attachmentId) {
                return $item;
            }
        }
        throw new RegistryNotFound();
    }

    /**
     * Arquivo para download, com as mesmas travas do Disco Virtual (escopo, caminho dentro da pasta privada,
     * bloqueio do antivírus).
     *
     * @return array{path:string, mime:string, name:string, size:int, sha256:string}
     */
    public function file(User $user, int $entryId, int $attachmentId): array
    {
        $this->entry($user, $entryId);
        $row = $this->row($entryId, $attachmentId);
        return $this->disk->file($user, (int)$row['document_id']);
    }

    /** Caminho absoluto de um arquivo já listado (livro caixa), ou null se sumiu do disco. */
    public function absolute(string $relative): ?string
    {
        return $this->disk->absolute($relative);
    }

    /** Lançamento no escopo do usuário (não excluído). */
    private function entry(User $user, int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, competence, description, nature, amount_cents FROM erp_entries WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($entry) || !$this->scope->canAccess($user, (int)$entry['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $entry;
    }

    private function row(int $entryId, int $attachmentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_entry_attachments WHERE id = :id AND entry_id = :e AND deleted_at IS NULL');
        $stmt->execute(['id' => $attachmentId, 'e' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private static function present(array $row, bool $withPath): array
    {
        $out = [
            'id' => (int)$row['id'], 'entry_id' => (int)$row['entry_id'], 'document_id' => (int)$row['document_id'],
            'kind' => (string)$row['kind'], 'kind_label' => self::KINDS[$row['kind']] ?? (string)$row['kind'],
            'title' => (string)$row['title'], 'original_name' => (string)$row['original_name'], 'mime' => (string)$row['mime'],
            'size_bytes' => (int)$row['size_bytes'], 'sha256' => (string)$row['sha256'],
            'scan_status' => (string)($row['scan_status'] ?? 'unscanned'), 'attached_at' => $row['created_at'],
        ];
        if ($withPath) {
            $out['path'] = (string)$row['path'];   // só para o livro caixa ler o arquivo; nunca sai na API
        }
        return $out;
    }
}
