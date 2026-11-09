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
 * Disco Virtual (AP-122, ADR 0047): arquivos do condomínio por pasta.
 *
 * - Guardados fora da pasta pública (storage/private/documents/{condomínio}/{ano}/{aleatório}.{ext}),
 *   com nome gerado pelo sistema: o nome enviado só aparece na listagem e no download.
 * - Tipo conferido pelo conteúdo (finfo), numa lista fechada (PDF, imagens, planilhas e textos do
 *   Office/LibreOffice, CSV e texto), com a extensão batendo com o tipo. No máximo 20 MB.
 * - O mesmo arquivo (sha256) já enviado na mesma pasta do condomínio é recusado.
 * - Download só com permissão e escopo (o controller manda como anexo, com nosniff).
 * - Exclusão lógica, auditada: o arquivo continua guardado.
 */
final class VirtualDisk
{
    public const MAX_BYTES = 20971520; // 20 MB
    public const FOLDERS = ['atas' => 'Atas', 'contratos' => 'Contratos', 'plantas' => 'Plantas', 'notas_fiscais' => 'Notas fiscais', 'outros' => 'Outros'];
    /** tipo (finfo) => extensões aceitas para ele */
    public const ALLOWED = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    private string $root;
    /** Testes e importações locais: aceita arquivo que não veio de upload HTTP. */
    private bool $acceptLocalFiles;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?string $root = null, bool $acceptLocalFiles = false)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->root = rtrim($root ?? self::defaultRoot(), '/');
        $this->acceptLocalFiles = $acceptLocalFiles;
    }

    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/private/documents';
    }

    /** Nome enviado, limpo para exibir e para o Content-Disposition (regra pura). */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F"\/\\\\:*?<>|]+/u', '', $name);
        $name = trim((string)preg_replace('/\s+/u', ' ', $name), ' .');
        return mb_substr($name !== '' ? $name : 'arquivo', 0, 160);
    }

    /** Extensão do nome enviado em minúsculas, ou ''. */
    public static function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : '';
    }

    /** O par tipo/extensão é aceito? (regra pura) */
    public static function accepts(string $mime, string $extension): bool
    {
        return in_array($extension, self::ALLOWED[$mime] ?? [], true);
    }

    /**
     * @param array{name?:string, tmp_name?:string, error?:int} $upload item de $_FILES
     * @param array{condominium_id?:mixed, folder?:mixed, title?:mixed} $input
     */
    public function upload(User $user, array $upload, array $input): array
    {
        $condominium = (int)filter_var($input['condominium_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($condominium <= 0 || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        $folder = is_string($input['folder'] ?? null) && isset(self::FOLDERS[$input['folder']]) ? $input['folder'] : null;
        if ($folder === null) {
            throw new InvalidArgumentException('Escolha a pasta: ' . implode(', ', array_keys(self::FOLDERS)) . '.');
        }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('O arquivo passa do tamanho máximo de 20 MB.');
        }
        $temporary = (string)($upload['tmp_name'] ?? '');
        $fromHttp = $temporary !== '' && is_uploaded_file($temporary);
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_file($temporary) || (!$fromHttp && !$this->acceptLocalFiles)) {
            throw new InvalidArgumentException('Selecione um arquivo.');
        }
        $size = (int)filesize($temporary);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('O arquivo está vazio ou passa do tamanho máximo de 20 MB.');
        }
        $original = self::cleanName((string)($upload['name'] ?? 'arquivo'));
        $extension = self::extension($original);
        $mime = strtolower((string)(new \finfo(FILEINFO_MIME_TYPE))->file($temporary));
        if (!self::accepts($mime, $extension)) {
            throw new InvalidArgumentException('Tipo de arquivo não aceito. Envie PDF, imagem (JPG, PNG, WebP), DOCX, XLSX, ODT, ODS, CSV ou TXT, com a extensão certa.');
        }
        $title = is_string($input['title'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['title'])) : '';
        $title = mb_substr($title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME), 0, 160);
        $sha = hash_file('sha256', $temporary);
        $dup = $this->pdo->prepare('SELECT id FROM erp_documents WHERE condominium_id = :c AND folder = :f AND sha256 = :h AND deleted_at IS NULL LIMIT 1');
        $dup->execute(['c' => $condominium, 'f' => $folder, 'h' => $sha]);
        if ($dup->fetchColumn()) {
            throw new DomainException('Este arquivo já está nesta pasta do condomínio.');
        }

        $relative = sprintf('%d/%s/%s.%s', $condominium, date('Y'), bin2hex(random_bytes(16)), $extension);
        $target = $this->root . '/' . $relative;
        $this->prepareDirectory(dirname($target));
        $stored = $fromHttp ? move_uploaded_file($temporary, $target) : copy($temporary, $target);
        if (!$stored) {
            throw new \RuntimeException('Não foi possível guardar o arquivo.');
        }
        @chmod($target, 0640);
        try {
            $this->pdo->prepare('INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256, uploaded_by)
                VALUES (:c, :f, :t, :o, :p, :m, :s, :h, :u)')->execute([
                'c' => $condominium, 'f' => $folder, 't' => $title, 'o' => $original, 'p' => $relative, 'm' => $mime, 's' => $size, 'h' => $sha, 'u' => (int)$user->id,
            ]);
        } catch (\Throwable $error) {
            @unlink($target);   // sem registro, o arquivo recém-copiado não fica órfão
            throw $error;
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_documents', $id, null, ['folder' => $folder, 'title' => $title, 'mime' => $mime, 'size_bytes' => $size, 'sha256' => $sha]);
        return $this->find($user, $id);
    }

    /** @return array{data:list<array>, total:int, page:int, per_page:int, pages:int} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $perPage = 50;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = 'd.deleted_at IS NULL AND d.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (is_string($filters['folder'] ?? null) && isset(self::FOLDERS[$filters['folder']])) {
            $where .= ' AND d.folder = :f';
            $params['f'] = $filters['folder'];
        }
        $q = is_string($filters['q'] ?? null) ? trim(mb_substr($filters['q'], 0, 100)) : '';
        if ($q !== '') {
            $where .= ' AND (d.title LIKE :q OR d.original_name LIKE :q2)';
            $params['q'] = $params['q2'] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_documents d WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT d.*, c.name AS condominium_name FROM erp_documents d JOIN condominiums c ON c.id = d.condominium_id
            WHERE {$where} ORDER BY d.created_at DESC, d.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    public function find(User $user, int $documentId): array
    {
        $row = $this->row($user, $documentId);
        $stmt = $this->pdo->prepare('SELECT name FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $row['condominium_id']]);
        return $this->present($row + ['condominium_name' => (string)$stmt->fetchColumn()]);
    }

    /**
     * Arquivo para download, conferindo permissão do chamador, escopo e que o caminho não sai da pasta.
     *
     * @return array{path:string, mime:string, name:string, size:int, sha256:string}
     */
    public function file(User $user, int $documentId): array
    {
        $row = $this->row($user, $documentId);
        $path = $this->absolute((string)$row['path']);
        if ($path === null) {
            throw new RegistryNotFound();
        }
        return ['path' => $path, 'mime' => (string)$row['mime'], 'name' => (string)$row['original_name'], 'size' => (int)$row['size_bytes'], 'sha256' => (string)$row['sha256']];
    }

    public function delete(User $user, int $documentId): void
    {
        $row = $this->row($user, $documentId);
        $this->pdo->prepare('UPDATE erp_documents SET deleted_at = NOW(), deleted_by = :u WHERE id = :id AND deleted_at IS NULL')
            ->execute(['u' => (int)$user->id, 'id' => $documentId]);
        Audit::record('delete', 'erp_documents', $documentId, ['title' => $row['title'], 'folder' => $row['folder']], null);
    }

    /** Caminho absoluto de um arquivo guardado, sem permitir sair da pasta privada. */
    public function absolute(string $relative): ?string
    {
        if (!preg_match('~^\d+/\d{4}/[a-f0-9]{32}\.[a-z0-9]{1,5}$~', $relative)) {
            return null;
        }
        $path = $this->root . '/' . $relative;
        return is_file($path) ? $path : null;
    }

    private function row(User $user, int $documentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_documents WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar a pasta do Disco Virtual.');
        }
        // Defesa extra caso a pasta storage fique dentro da raiz pública do Apache.
        $base = dirname($this->root);
        if (is_dir($base) && !is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => $row['condominium_name'] ?? null,
            'folder' => (string)$row['folder'], 'folder_label' => self::FOLDERS[$row['folder']] ?? (string)$row['folder'],
            'title' => (string)$row['title'], 'original_name' => (string)$row['original_name'], 'mime' => (string)$row['mime'],
            'size_bytes' => (int)$row['size_bytes'], 'sha256' => (string)$row['sha256'], 'uploaded_at' => $row['created_at'],
        ];
    }
}
