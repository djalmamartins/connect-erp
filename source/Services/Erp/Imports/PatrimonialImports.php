<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PatrimonialEntries;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Importação de lançamentos patrimoniais por CSV ou XLSX (ADR 0171). Segue o mesmo fluxo das outras
 * importações (Imports, ADR 0058): uma prévia com o erro de cada linha e, depois, a aplicação, que revalida o
 * arquivo guardado e grava tudo numa transação (tudo ou nada). Usa o mesmo leitor (SpreadsheetReader) e as
 * mesmas regras de valor e data (ImportRules).
 *
 * Cada linha passa pelas regras do PatrimonialEntries (ADR 0169): duas contas patrimoniais analíticas,
 * diferentes, do padrão ou do condomínio; nunca as calculadas (7.1, 7.2, 8.1); data até hoje em competência
 * aberta; conta bancária e lançamento de origem, se houver, do mesmo condomínio. A gravação chama o
 * próprio PatrimonialEntries::create, que valida de novo e audita cada lançamento.
 *
 * Idempotência: a chave enviada pelo cliente (Idempotency-Key) ou, sem ela, o sha256 do arquivo. É única
 * por condomínio. Se a importação já foi aplicada, a nova tentativa recebe 409 e nada é gravado. Se ainda
 * está em prévia, ela é revalidada no mesmo registro.
 * Escopo (ADR-002): fora do escopo = 404.
 */
final class PatrimonialImports
{
    public const MAX_BYTES = 2_000_000;
    public const FORMATS = ['csv', 'xlsx'];
    public const REQUIRED = ['data', 'descricao', 'valor', 'conta_debito', 'conta_credito'];
    public const OPTIONAL = ['conta_bancaria', 'lancamento_origem'];
    public const MAX_ROWS = 5000;

    private PDO $pdo;
    private CondominiumScope $scope;
    private bool $acceptLocalFiles;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, bool $acceptLocalFiles = false)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->acceptLocalFiles = $acceptLocalFiles;
    }

    /** Chave de idempotência (regra pura): a do cliente, se válida, ou "sha256:<hash do arquivo>". */
    public static function key(mixed $clientKey, string $sha256): string
    {
        if (is_string($clientKey) && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $clientKey) === 1) {
            return $clientKey;
        }
        return 'sha256:' . $sha256;
    }

    /** @param array{condominium_id?:mixed, idempotency_key?:mixed} $input */
    public function preview(User $user, array $input, array $upload, ?string $today = null): array
    {
        $condominium = $this->condominium($user, $input['condominium_id'] ?? null);
        [$content, $name] = $this->readUpload($upload);
        $sha = hash('sha256', $content);
        $key = self::key($input['idempotency_key'] ?? null, $sha);
        $existing = $this->byKey($condominium, $key);
        if ($existing !== null && $existing['status'] === 'applied') {
            throw new DomainException('Esta importação já foi aplicada neste condomínio (importação patrimonial nº ' . $existing['id'] . ').');
        }
        if ($existing !== null && $existing['sha256'] !== $sha) {
            throw new DomainException('Esta chave de idempotência já foi usada com outro arquivo. Use outra chave.');
        }
        $result = $this->validate($condominium, $content, $name, $today ?? date('Y-m-d'));
        $summary = json_encode($result['summary'], JSON_UNESCAPED_UNICODE);
        if ($existing !== null) {
            $this->pdo->prepare('UPDATE erp_patrimonial_imports SET rows_count = :r, error_count = :e, summary_json = :s WHERE id = :id')
                ->execute(['r' => count($result['rows']), 'e' => $result['error_count'], 's' => $summary, 'id' => $existing['id']]);
            $id = (int)$existing['id'];
        } else {
            try {
                $this->pdo->prepare('INSERT INTO erp_patrimonial_imports (condominium_id, idempotency_key, sha256, original_name, content, status, rows_count, error_count, summary_json, created_by)
                    VALUES (:c, :k, :h, :n, :b, :st, :r, :e, :s, :u)')->execute(['c' => $condominium, 'k' => $key, 'h' => $sha, 'n' => $name, 'b' => $content, 'st' => 'previewed',
                    'r' => count($result['rows']), 'e' => $result['error_count'], 's' => $summary, 'u' => (int)$user->id]);
            } catch (PDOException $error) {
                if (!in_array($error->errorInfo[1] ?? null, [1062, 19], true)) {
                    throw $error;
                }
                return $this->preview($user, $input, $upload, $today);   // pedido concorrente gravou primeiro: revalida sobre ele
            }
            $id = (int)$this->pdo->lastInsertId();
            Audit::record('create', 'erp_patrimonial_imports', $id, null, ['condominium_id' => $condominium, 'file' => $name, 'rows' => count($result['rows']), 'errors' => $result['error_count']]);
        }
        return $this->find($user, $id) + ['preview' => $result['rows']];
    }

    /** Aplica a prévia: revalida o arquivo guardado e grava todos os lançamentos numa transação. */
    public function apply(User $user, int $importId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $import = $this->row($user, $importId);
        if ($import['status'] === 'applied') {
            throw new DomainException('Esta importação já foi aplicada.');
        }
        $content = (string)$import['content'];
        if (!hash_equals((string)$import['sha256'], hash('sha256', $content))) {
            throw new DomainException('O arquivo guardado não confere com a prévia. Envie de novo.');
        }
        $condominium = (int)$import['condominium_id'];
        $entries = new PatrimonialEntries($this->pdo, $this->scope);
        $this->pdo->beginTransaction();
        try {
            // Trava a importação: duas aplicações simultâneas não gravam em dobro.
            $lock = $this->pdo->prepare("UPDATE erp_patrimonial_imports SET status = 'applied', applied_by = :u, applied_at = :at WHERE id = :id AND status = 'previewed'");
            $lock->execute(['u' => (int)$user->id, 'at' => $today . ' ' . date('H:i:s'), 'id' => $importId]);
            if ($lock->rowCount() !== 1) {
                throw new DomainException('Esta importação já foi aplicada.');
            }
            $result = $this->validate($condominium, $content, (string)$import['original_name'], $today);
            if ($result['error_count'] > 0) {
                throw new DomainException(sprintf('O arquivo tem %d linha(s) com erro. Corrija e envie de novo; nada foi gravado.', $result['error_count']));
            }
            $link = $this->pdo->prepare('UPDATE erp_patrimonial_entries SET import_id = :i WHERE id = :id');
            $ids = [];
            foreach ($result['rows'] as $row) {
                $made = $entries->create($user, $row['data'] + ['condominium_id' => $condominium], $today);
                $link->execute(['i' => $importId, 'id' => $made['id']]);
                $ids[] = $made['id'];
            }
            $summary = $result['summary'] + ['applied' => ['entries' => count($ids), 'entry_ids' => $ids]];
            $this->pdo->prepare('UPDATE erp_patrimonial_imports SET summary_json = :s WHERE id = :id')->execute(['s' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'id' => $importId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_patrimonial_imports', $importId, ['status' => 'previewed'], ['status' => 'applied', 'entries' => count($ids)]);
        return $this->find($user, $importId);
    }

    public function find(User $user, int $importId): array
    {
        $r = $this->row($user, $importId);
        return ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'idempotency_key' => (string)$r['idempotency_key'], 'original_name' => (string)$r['original_name'],
            'status' => (string)$r['status'], 'rows_count' => (int)$r['rows_count'], 'error_count' => (int)$r['error_count'],
            'summary' => $r['summary_json'] !== null ? json_decode((string)$r['summary_json'], true) : null, 'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null,
            'applied_by' => $r['applied_by'] !== null ? (int)$r['applied_by'] : null, 'applied_at' => $r['applied_at']];
    }

    /** Valida o arquivo (prévia e aplicação): erros por linha, sem gravar nada. */
    private function validate(int $condominium, string $content, string $name, string $today): array
    {
        $sheet = SpreadsheetReader::read($content, $name);
        $missing = array_diff(self::REQUIRED, $sheet['header']);
        if ($missing !== []) {
            throw new InvalidArgumentException('Faltam colunas obrigatórias: ' . implode(', ', $missing) . '. Esperadas: ' . implode(', ', self::REQUIRED)
                . ' (opcionais: ' . implode(', ', self::OPTIONAL) . ').');
        }
        if (count($sheet['rows']) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Até ' . self::MAX_ROWS . ' linhas por arquivo.');
        }
        $accounts = $this->accounts($condominium);
        $banks = $this->banks($condominium);
        $closed = [];
        $rows = [];
        $errors = 0;
        $total = 0;
        foreach ($sheet['rows'] as $raw) {
            $v = $raw['values'];
            $problems = [];
            $date = ImportRules::date($v['data'] ?? '');
            if ($date === null || $date > $today || $date < '2000-01-01') {
                $problems[] = 'Data inválida (DD/MM/AAAA ou AAAA-MM-DD, até hoje).';
            } else {
                $month = substr($date, 0, 7);
                $closed[$month] ??= $this->closed($condominium, $month);
                if ($closed[$month]) {
                    $problems[] = "A competência {$month} está fechada.";
                }
            }
            $description = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($v['descricao'] ?? '')));
            if (mb_strlen($description) < 3 || mb_strlen($description) > 255) {
                $problems[] = 'Descrição de 3 a 255 caracteres.';
            }
            $amount = ImportRules::money($v['valor'] ?? '');
            if ($amount === null) {
                $problems[] = 'Valor inválido (positivo, ex.: 1.234,56).';
            }
            $debit = $this->account($accounts, $v['conta_debito'] ?? '', 'débito', $problems);
            $credit = $this->account($accounts, $v['conta_credito'] ?? '', 'crédito', $problems);
            if ($debit !== null && $debit === $credit) {
                $problems[] = 'O débito e o crédito precisam ser em contas diferentes.';
            }
            $bankText = trim($v['conta_bancaria'] ?? '');
            $bank = null;
            if ($bankText !== '') {
                $bank = $banks['id'][$bankText] ?? $banks['name'][mb_strtolower($bankText)] ?? null;
                if ($bank === null) {
                    $problems[] = "Conta bancária \"{$bankText}\" não existe neste condomínio.";
                }
            }
            $originText = trim($v['lancamento_origem'] ?? '');
            $origin = null;
            if ($originText !== '') {
                $origin = $this->entry($condominium, $originText);
                if ($origin === null) {
                    $problems[] = "Lançamento de origem \"{$originText}\" não existe neste condomínio.";
                }
            }
            $ok = $problems === [];
            $errors += $ok ? 0 : 1;
            $total += $ok ? (int)$amount : 0;
            $rows[] = ['line' => $raw['line'], 'status' => $ok ? 'ok' : 'error', 'errors' => $problems,
                'data' => ['entry_date' => $date, 'description' => $description, 'amount_cents' => $amount, 'debit_account_id' => $debit, 'credit_account_id' => $credit,
                    'bank_account_id' => $bank, 'linked_entry_id' => $origin]];
        }
        if ($rows === []) {
            throw new InvalidArgumentException('O arquivo não tem linhas para importar.');
        }
        return ['rows' => $rows, 'error_count' => $errors, 'summary' => ['rows' => count($rows), 'errors' => $errors, 'ok' => count($rows) - $errors, 'amount_cents' => $total]];
    }

    /** @param array<string, array{id:int, kind:string, nature:string}> $accounts @param list<string> $problems */
    private function account(array $accounts, string $code, string $side, array &$problems): ?int
    {
        $code = trim($code);
        $account = $accounts[$code] ?? null;
        if ($account === null || !in_array($account['nature'], PatrimonialEntries::NATURES, true) || $account['kind'] !== 'analitica') {
            $problems[] = "Conta de {$side} \"{$code}\": use uma conta patrimonial analítica ativa (ativo, passivo ou PL) do padrão ou deste condomínio.";
            return null;
        }
        if (PatrimonialEntries::computed($code)) {
            $problems[] = "A conta {$code} é calculada pelo balanço e não recebe lançamento patrimonial.";
            return null;
        }
        return $account['id'];
    }

    /** Contas do plano por código, a do condomínio antes da do padrão. @return array<string, array{id:int, kind:string, nature:string}> */
    private function accounts(int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT id, code, kind, nature FROM erp_chart_accounts WHERE deleted_at IS NULL AND status = 'active'
            AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY condominium_id IS NULL");
        $stmt->execute(['c' => $condominium]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['code']] ??= ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'nature' => (string)$r['nature']];
        }
        return $out;
    }

    /** @return array{id: array<string,int>, name: array<string,int>} */
    private function banks(int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name FROM erp_bank_accounts WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['c' => $condominium]);
        $out = ['id' => [], 'name' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['id'][(string)$r['id']] = (int)$r['id'];
            $out['name'][mb_strtolower((string)$r['name'])] = (int)$r['id'];
        }
        return $out;
    }

    private function entry(int $condominium, string $id): ?int
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM erp_entries WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => (int)$id, 'c' => $condominium]);
        $found = $stmt->fetchColumn();
        return $found === false ? null : (int)$found;
    }

    /**
     * Competência fechada? Usa RegistryService::isClosed: dentro da transação do apply(), a leitura é travada
     * (ADR 0100, só no MariaDB), e assim o mês não fecha no meio da gravação.
     */
    private function closed(int $condominium, string $month): bool
    {
        return (new RegistryService($this->pdo, $this->scope))->isClosed($condominium, $month);
    }

    private function condominium(User $user, mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 fora do escopo
        return (int)$id;
    }

    /** @return array{0:string, 1:string} conteúdo e nome saneado */
    private function readUpload(array $upload): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $temporary = (string)($upload['tmp_name'] ?? '');
        $fromHttp = $temporary !== '' && is_uploaded_file($temporary);
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_file($temporary) || (!$fromHttp && !$this->acceptLocalFiles)) {
            throw new InvalidArgumentException('Envie o arquivo da importação.');
        }
        $size = (int)filesize($temporary);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('O arquivo precisa ter até 2 MB.');
        }
        $name = basename(str_replace('\\', '/', (string)($upload['name'] ?? 'arquivo')));
        $name = mb_substr(trim((string)preg_replace('/[^\w.\- ]+/u', '_', $name), ' .') ?: 'arquivo', 0, 160);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::FORMATS, true)) {
            throw new InvalidArgumentException('Formato não aceito: use CSV ou XLSX.');
        }
        $content = (string)file_get_contents($temporary);
        if ($extension === 'xlsx' && !str_starts_with($content, "PK\x03\x04")) {
            throw new InvalidArgumentException('O arquivo não é um XLSX válido.');
        }
        if ($extension === 'csv' && str_contains($content, "\0")) {
            throw new InvalidArgumentException('O arquivo não é texto (CSV).');
        }
        return [$content, $name];
    }

    private function byKey(int $condominium, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, sha256 FROM erp_patrimonial_imports WHERE condominium_id = :c AND idempotency_key = :k');
        $stmt->execute(['c' => $condominium, 'k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function row(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_patrimonial_imports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied(is_array($row) ? (int)$row['condominium_id'] : 0);   // 404, sem dizer se existe
        }
        return $row;
    }
}
