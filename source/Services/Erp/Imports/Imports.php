<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Support\Audit;

/**
 * Importações do ERP (ADR 0058). Duas etapas:
 *
 * 1. preview(): lê o arquivo inteiro e valida todas as linhas (formato, conta do plano, unidade,
 *    conta bancária, fornecedor, competência fechada, regras do cadastro). Guarda o arquivo em
 *    storage/private/imports e devolve a prévia, com o erro de cada linha. Nada é lançado.
 * 2. apply(): relê o arquivo guardado (não confia na prévia), valida de novo e, sem nenhum erro,
 *    grava tudo numa transação só. Qualquer falha desfaz tudo.
 *
 * O mesmo arquivo (sha256) não é aplicado duas vezes no mesmo condomínio e tipo. O extrato OFX não
 * cria nem baixa lançamento: só concilia com o que já está pago (ImportRules::match), e o que não
 * bate fica pendente para resolver à mão. Escopo por condomínio (ADR-002); tudo auditado.
 */
final class Imports
{
    public const MAX_BYTES = 2_000_000;

    /** Tipos: página, rótulo, formatos, colunas obrigatórias e opcionais, permissão do destino. */
    public const KINDS = [
        'ofx' => ['page' => 'AP-140', 'label' => 'Extrato bancário (OFX)', 'formats' => ['ofx'], 'required' => [], 'optional' => [], 'permission' => 'erp.entries.manage'],
        'entries' => ['page' => 'AP-144', 'label' => 'Lançamentos (planilha)', 'formats' => ['csv', 'xlsx'],
            'required' => ['natureza', 'descricao', 'valor', 'competencia', 'vencimento', 'conta'],
            'optional' => ['pago_em', 'conta_bancaria', 'unidade', 'documento', 'fornecedor'], 'permission' => 'erp.entries.manage'],
        'unit_entries' => ['page' => 'AP-145', 'label' => 'Lançamentos por unidade', 'formats' => ['csv', 'xlsx'],
            'required' => ['unidade', 'descricao', 'valor', 'competencia', 'vencimento', 'conta'], 'optional' => ['documento'], 'permission' => 'erp.entries.manage'],
        'payables' => ['page' => 'AP-146', 'label' => 'Contas a pagar (planilha)', 'formats' => ['csv', 'xlsx'],
            'required' => ['descricao', 'valor', 'competencia', 'vencimento', 'conta'], 'optional' => ['fornecedor', 'documento'], 'permission' => 'erp.entries.manage'],
        'fractions' => ['page' => 'AP-143', 'label' => 'Frações ideais (planilha)', 'formats' => ['csv', 'xlsx'],
            'required' => ['unidade', 'fracao'], 'optional' => [], 'permission' => 'erp.units.manage'],
        'delinquents' => ['page' => 'AP-142', 'label' => 'Inadimplentes (planilha)', 'formats' => ['csv', 'xlsx'],
            'required' => ['unidade', 'descricao', 'valor', 'competencia', 'vencimento', 'conta'], 'optional' => ['documento'], 'permission' => 'erp.entries.manage'],
    ];
    /** Soma das frações aceita (o arquivo traz todas as unidades ativas). */
    public const FRACTION_TOLERANCE = 0.0001;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;
    private string $root;
    private bool $acceptLocalFiles;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?string $root = null, bool $acceptLocalFiles = false)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
        $this->root = rtrim($root ?? dirname(__DIR__, 4) . '/storage/private/imports', '/');
        $this->acceptLocalFiles = $acceptLocalFiles;
    }

    /**
     * Prévia de um arquivo enviado ($upload no formato de $_FILES). Mesmo arquivo já em prévia:
     * devolve a mesma importação, revalidada. Já aplicado: recusa.
     *
     * @param array{condominium_id?:mixed, kind?:mixed, bank_account_id?:mixed} $input
     */
    public function preview(User $user, string $kind, array $input, array $upload): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Tipo de importação inválido.');
        }
        $condominium = $this->int($input['condominium_id'] ?? null);
        if ($condominium === null || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        [$content, $name] = $this->readUpload($upload, $kind);
        $bankAccount = null;
        if ($kind === 'ofx') {
            $bankAccount = $this->bankAccount($this->int($input['bank_account_id'] ?? null), $condominium);
        }
        $hash = hash('sha256', $content);
        $existing = $this->byHash($condominium, $kind, $hash);
        if ($existing !== null && $existing['status'] === 'applied') {
            throw new DomainException('Este arquivo já foi importado neste condomínio (importação nº ' . $existing['id'] . ').');
        }
        $result = $this->validate($kind, $condominium, $bankAccount, $content, $name);
        $summary = json_encode($result['summary'], JSON_UNESCAPED_UNICODE);
        if ($existing !== null) {
            $this->pdo->prepare('UPDATE erp_imports SET rows_count = :r, error_count = :e, summary_json = :s, bank_account_id = :b WHERE id = :id')
                ->execute(['r' => count($result['rows']), 'e' => $result['error_count'], 's' => $summary, 'b' => $bankAccount['id'] ?? null, 'id' => $existing['id']]);
            $id = (int)$existing['id'];
        } else {
            $relative = $condominium . '/' . $hash . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $this->store($relative, $content);
            try {
                $this->pdo->prepare('INSERT INTO erp_imports (condominium_id, kind, bank_account_id, original_name, sha256, storage_path, size_bytes, rows_count, error_count, summary_json, created_by)
                    VALUES (:c, :k, :b, :n, :h, :p, :sz, :r, :e, :s, :u)')->execute(['c' => $condominium, 'k' => $kind, 'b' => $bankAccount['id'] ?? null, 'n' => $name,
                    'h' => $hash, 'p' => $relative, 'sz' => strlen($content), 'r' => count($result['rows']), 'e' => $result['error_count'], 's' => $summary, 'u' => (int)$user->id]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    throw $error;
                }
                return $this->preview($user, $kind, $input, $upload);   // pedido concorrente gravou primeiro: revalida sobre ele
            }
            $id = (int)$this->pdo->lastInsertId();
            Audit::record('create', 'erp_imports', $id, null, ['kind' => $kind, 'file' => $name, 'rows' => count($result['rows']), 'errors' => $result['error_count']]);
        }
        return $this->find($user, $id) + ['preview' => $result['rows']];
    }

    /** Aplica a prévia: revalida o arquivo guardado e grava tudo numa transação. */
    public function apply(User $user, int $importId): array
    {
        $import = $this->row($user, $importId);
        if ($import['status'] === 'applied') {
            throw new DomainException('Esta importação já foi aplicada.');
        }
        $content = $this->load((string)$import['storage_path']);
        if (!hash_equals((string)$import['sha256'], hash('sha256', $content))) {
            throw new DomainException('O arquivo guardado não confere com a prévia. Envie de novo.');
        }
        $condominium = (int)$import['condominium_id'];
        $kind = (string)$import['kind'];
        $bankAccount = $kind === 'ofx' ? $this->bankAccount((int)$import['bank_account_id'], $condominium) : null;

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM erp_imports WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $importId]);
            if ($lock->fetchColumn() !== 'previewed') {
                throw new DomainException('Esta importação já foi aplicada.');
            }
            $result = $this->validate($kind, $condominium, $bankAccount, $content, (string)$import['original_name']);
            if ($result['error_count'] > 0) {
                throw new DomainException(sprintf('O arquivo tem %d linha(s) com erro. Corrija e envie de novo; nada foi gravado.', $result['error_count']));
            }
            $applied = match ($kind) {
                'ofx' => $this->applyOfx($user, $importId, $bankAccount, $result),
                'fractions' => $this->applyFractions($user, $result['rows']),
                default => $this->applyEntries($user, $importId, $result['rows']),
            };
            $summary = $result['summary'] + ['applied' => $applied];
            $this->pdo->prepare("UPDATE erp_imports SET status = 'applied', applied_by = :u, applied_at = NOW(), summary_json = :s WHERE id = :id")
                ->execute(['u' => (int)$user->id, 's' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'id' => $importId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_imports', $importId, ['status' => 'previewed'], ['status' => 'applied', 'kind' => $kind, 'applied' => $applied]);
        return $this->find($user, $importId);
    }

    /** @return array{data:list<array>, total:int} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 'i.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (isset(self::KINDS[$filters['kind'] ?? ''])) {
            $where .= ' AND i.kind = :k';
            $params['k'] = $filters['kind'];
        }
        $stmt = $this->pdo->prepare("SELECT i.*, c.name AS condominium_name FROM erp_imports i JOIN condominiums c ON c.id = i.condominium_id
            WHERE {$where} ORDER BY i.id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => count($rows)];
    }

    public function find(User $user, int $importId): array
    {
        $out = $this->present($this->row($user, $importId));
        if ($out['kind'] === 'ofx') {
            $stmt = $this->pdo->prepare('SELECT id, fitid, kind, posted_on, amount_cents, memo, status, entry_id, transfer_id, note FROM erp_bank_statement_lines WHERE import_id = :i ORDER BY posted_on, id');
            $stmt->execute(['i' => $importId]);
            $out['lines'] = array_map(fn (array $r): array => $this->presentLine($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $out;
    }

    /** Linhas do extrato pendentes de conciliação ($condominiumId null = todos do escopo). */
    public function pendingLines(User $user, ?int $condominiumId = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $rows = $this->pdo->query("SELECT l.id, l.fitid, l.kind, l.posted_on, l.amount_cents, l.memo, l.status, l.entry_id, l.transfer_id, l.note, l.bank_account_id, b.name AS bank_account
            FROM erp_bank_statement_lines l JOIN erp_bank_accounts b ON b.id = l.bank_account_id
            WHERE l.condominium_id IN (" . implode(',', $ids) . ") AND l.status = 'unmatched' ORDER BY l.posted_on, l.id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(fn (array $r): array => $this->presentLine($r) + ['bank_account_id' => (int)$r['bank_account_id'], 'bank_account' => (string)$r['bank_account']], $rows);
        return ['data' => $data, 'total' => count($data)];
    }

    /**
     * Resolve uma linha pendente: 'match' com um lançamento pago da mesma conta bancária, mesmo
     * sentido e mesmo valor ainda não conciliado; ou 'ignore' (tarifa já lançada de outro jeito, por
     * exemplo). Nota obrigatória nos dois casos.
     */
    public function resolveLine(User $user, int $lineId, string $action, mixed $entryId, mixed $note): array
    {
        $note = is_string($note) ? trim((string)preg_replace('/\s+/u', ' ', $note)) : '';
        if (mb_strlen($note) < 5 || mb_strlen($note) > 255) {
            throw new InvalidArgumentException('Informe a nota da conciliação (5 a 255 caracteres).');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_statement_lines WHERE id = :id');
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($line) || !$this->scope->canAccess($user, (int)$line['condominium_id'])) {
            throw new RegistryNotFound();
        }
        if ($line['status'] !== 'unmatched') {
            throw new DomainException('A linha já foi conciliada ou ignorada.');
        }
        $entry = null;
        if ($action === 'match') {
            $entry = $this->int($entryId);
            $check = $this->pdo->prepare("SELECT e.id FROM erp_entries e LEFT JOIN erp_bank_statement_lines l ON l.entry_id = e.id
                WHERE e.id = :e AND e.bank_account_id = :b AND e.paid_on IS NOT NULL AND e.status = 'active' AND e.deleted_at IS NULL
                  AND e.nature = :n AND e.amount_cents = :v AND l.id IS NULL");
            $check->execute(['e' => (int)$entry, 'b' => $line['bank_account_id'], 'n' => (int)$line['amount_cents'] >= 0 ? 'receita' : 'despesa', 'v' => abs((int)$line['amount_cents'])]);
            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException('Escolha um lançamento pago desta conta bancária, do mesmo valor e sentido, ainda não conciliado.');
            }
        } elseif ($action !== 'ignore') {
            throw new InvalidArgumentException('Ação inválida: match ou ignore.');
        }
        $this->pdo->prepare('UPDATE erp_bank_statement_lines SET status = :s, entry_id = :e, note = :n, resolved_by = :u, resolved_at = NOW() WHERE id = :id AND status = \'unmatched\'')
            ->execute(['s' => $action === 'match' ? 'matched' : 'ignored', 'e' => $entry, 'n' => $note, 'u' => (int)$user->id, 'id' => $lineId]);
        Audit::record('update', 'erp_bank_statement_lines', $lineId, ['status' => 'unmatched'], ['status' => $action === 'match' ? 'matched' : 'ignored', 'entry_id' => $entry, 'note' => $note]);
        $stmt->execute(['id' => $lineId]);
        return $this->presentLine($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Valida o arquivo inteiro. Linhas: {line, status ok|error, errors[], data}. summary: contagens e totais.
     *
     * @return array{rows:list<array>, error_count:int, summary:array}
     */
    private function validate(string $kind, int $condominium, ?array $bankAccount, string $content, string $name): array
    {
        if ($kind === 'ofx') {
            return $this->validateOfx($condominium, $bankAccount, $content);
        }
        $sheet = SpreadsheetReader::read($content, $name);
        $definition = self::KINDS[$kind];
        $missing = array_diff($definition['required'], $sheet['header']);
        if ($missing !== []) {
            throw new InvalidArgumentException('Faltam colunas obrigatórias: ' . implode(', ', $missing) . '. Esperadas: ' . implode(', ', $definition['required'])
                . ($definition['optional'] !== [] ? ' (opcionais: ' . implode(', ', $definition['optional']) . ')' : '') . '.');
        }
        $lookups = $this->lookups($condominium);
        $rows = [];
        $errors = 0;
        $total = 0;
        $seenUnits = [];
        foreach ($sheet['rows'] as $raw) {
            $v = $raw['values'];
            $problems = [];
            if ($kind === 'fractions') {
                $unit = $lookups['units'][mb_strtolower($v['unidade'])] ?? null;
                $fraction = ImportRules::fraction($v['fracao']);
                if ($unit === null) {
                    $problems[] = "Unidade \"{$v['unidade']}\" não existe neste condomínio.";
                } elseif (isset($seenUnits[$unit])) {
                    $problems[] = "Unidade \"{$v['unidade']}\" repetida.";
                }
                if ($fraction === null) {
                    $problems[] = 'Fração inválida (maior que 0 e até 1, ex.: 0,012500 ou 1,25%).';
                }
                if ($unit !== null) {
                    $seenUnits[$unit] = true;
                }
                $rows[] = ['line' => $raw['line'], 'status' => $problems === [] ? 'ok' : 'error', 'errors' => $problems,
                    'data' => ['unit_id' => $unit, 'unit' => $v['unidade'], 'ideal_fraction' => $fraction]];
                $errors += $problems === [] ? 0 : 1;
                continue;
            }
            $nature = match ($kind) {
                'entries' => ImportRules::nature($v['natureza'] ?? ''),
                'payables' => 'despesa',
                default => 'receita',
            };
            $amount = ImportRules::money($v['valor'] ?? '');
            $competence = ImportRules::competence($v['competencia'] ?? '');
            $due = ImportRules::date($v['vencimento'] ?? '');
            $account = $lookups['accounts'][trim($v['conta'] ?? '')] ?? null;
            $unitText = trim($v['unidade'] ?? '');
            $unit = $unitText !== '' ? ($lookups['units'][mb_strtolower($unitText)] ?? null) : null;
            $paid = trim($v['pago_em'] ?? '') !== '' ? ImportRules::date($v['pago_em']) : null;
            $bankText = trim($v['conta_bancaria'] ?? '');
            $bank = $bankText !== '' ? ($lookups['banks'][mb_strtolower($bankText)] ?? null) : null;
            $supplierText = (string)preg_replace('/\D/', '', $v['fornecedor'] ?? '');
            $supplier = $supplierText !== '' ? ($lookups['suppliers'][$supplierText] ?? null) : null;
            if ($nature === null) {
                $problems[] = 'Natureza inválida (receita ou despesa).';
            }
            if ($amount === null) {
                $problems[] = 'Valor inválido (ex.: 1.234,56).';
            }
            if ($competence === null) {
                $problems[] = 'Competência inválida (MM/AAAA).';
            } elseif ($this->registry->isClosed($condominium, $competence)) {
                $problems[] = "A competência {$competence} está fechada.";
            }
            if ($due === null) {
                $problems[] = 'Vencimento inválido (DD/MM/AAAA).';
            }
            if ($account === null) {
                $problems[] = 'Conta do plano "' . ($v['conta'] ?? '') . '" não existe (use o código de uma conta analítica ativa).';
            } elseif ($nature !== null && $account['nature'] !== $nature) {
                $problems[] = "A conta {$account['code']} é de {$account['nature']}, e a linha é de {$nature}.";
            }
            if (in_array($kind, ['unit_entries', 'delinquents'], true) && $unitText === '') {
                $problems[] = 'Informe a unidade.';
            }
            if ($unitText !== '' && $unit === null) {
                $problems[] = "Unidade \"{$unitText}\" não existe neste condomínio.";
            }
            if ($kind === 'delinquents' && $due !== null && $due >= date('Y-m-d')) {
                $problems[] = 'Inadimplente precisa de vencimento no passado.';
            }
            if (trim($v['pago_em'] ?? '') !== '' && $paid === null) {
                $problems[] = 'Data de pagamento inválida.';
            }
            if ($paid !== null && $paid > date('Y-m-d')) {
                $problems[] = 'Pagamento no futuro.';
            }
            if ($paid !== null && $bank === null) {
                $problems[] = $bankText === '' ? 'Lançamento pago precisa da conta bancária.' : "Conta bancária \"{$bankText}\" não existe neste condomínio.";
            }
            if ($supplierText !== '' && $supplier === null) {
                $problems[] = 'Fornecedor não encontrado pelo CNPJ/CPF.';
            }
            $data = null;
            if ($problems === []) {
                $data = array_filter([
                    'condominium_id' => $condominium, 'nature' => $nature, 'chart_account_id' => $account['id'],
                    'description' => mb_substr(trim($v['descricao'] ?? ''), 0, 160),
                    'amount_cents' => number_format($amount / 100, 2, ',', ''), 'competence' => $competence, 'due_on' => $due,
                    'paid_on' => $paid, 'bank_account_id' => $paid !== null ? $bank : null, 'unit_id' => $unit, 'supplier_id' => $supplier,
                    'document_number' => trim($v['documento'] ?? '') !== '' ? mb_substr(trim($v['documento']), 0, 40) : null,
                ], static fn ($x): bool => $x !== null);
                // Mesmas regras do cadastro de lançamentos (tamanhos, obrigatórios, "pago exige conta").
                [, $fieldErrors] = RegistryValidator::validate(Definitions::get('entries'), $data);
                foreach ($fieldErrors as $field => $message) {
                    $problems[] = "{$field}: {$message}";
                }
                $total += $amount;
            }
            $rows[] = ['line' => $raw['line'], 'status' => $problems === [] ? 'ok' : 'error', 'errors' => $problems,
                'data' => $data ?? ['description' => $v['descricao'] ?? '', 'amount' => $v['valor'] ?? '']];
            $errors += $problems === [] ? 0 : 1;
        }
        $summary = ['rows' => count($rows), 'errors' => $errors];
        if ($kind === 'fractions') {
            $sum = array_sum(array_map(static fn (array $r): float => (float)($r['data']['ideal_fraction'] ?? 0), $rows));
            $missingUnits = array_diff(array_values($lookups['units']), array_keys($seenUnits));
            $summary += ['fraction_sum' => round($sum, 6), 'missing_units' => count($missingUnits)];
            if ($errors === 0 && $missingUnits !== []) {
                $rows[] = ['line' => null, 'status' => 'error', 'errors' => [sprintf('Faltam %d unidade(s) ativa(s) no arquivo: a planilha precisa trazer todas.', count($missingUnits))], 'data' => null];
                $errors++;
            }
            if ($errors === 0 && abs($sum - 1) > self::FRACTION_TOLERANCE) {
                $rows[] = ['line' => null, 'status' => 'error', 'errors' => [sprintf('A soma das frações é %s; precisa ser 1 (100%%).', number_format($sum, 6, ',', ''))], 'data' => null];
                $errors++;
            }
        } else {
            $summary['total_cents'] = $total;
        }
        $summary['errors'] = $errors;
        return ['rows' => $rows, 'error_count' => $errors, 'summary' => $summary];
    }

    private function validateOfx(int $condominium, array $bankAccount, string $content): array
    {
        $ofx = OfxParser::parse($content);
        if (!OfxParser::sameAccount($ofx['account_id'], (string)$bankAccount['account_number'])) {
            throw new InvalidArgumentException("O extrato é da conta {$ofx['account_id']}, e não da conta bancária escolhida ({$bankAccount['account_number']}).");
        }
        $rows = [];
        $errors = 0;
        $credits = 0;
        $debits = 0;
        $existing = $this->pdo->prepare('SELECT 1 FROM erp_bank_statement_lines WHERE bank_account_id = :b AND fitid = :f');
        foreach ($ofx['transactions'] as $index => $transaction) {
            $problems = [];
            if ($this->registry->isClosed($condominium, substr($transaction['posted_on'], 0, 7))) {
                $problems[] = 'A competência ' . substr($transaction['posted_on'], 0, 7) . ' está fechada.';
            }
            $existing->execute(['b' => $bankAccount['id'], 'f' => $transaction['fitid']]);
            $already = $existing->fetchColumn() !== false;
            $rows[] = ['line' => $index + 1, 'status' => $problems === [] ? ($already ? 'already' : 'ok') : 'error', 'errors' => $problems, 'data' => $transaction];
            $errors += $problems === [] ? 0 : 1;
            if ($transaction['amount_cents'] >= 0) {
                $credits += $transaction['amount_cents'];
            } else {
                $debits -= $transaction['amount_cents'];
            }
        }
        return ['rows' => $rows, 'error_count' => $errors, 'ofx' => $ofx, 'summary' => [
            'rows' => count($rows), 'errors' => $errors, 'account' => $ofx['account_id'], 'from' => $ofx['from'], 'to' => $ofx['to'],
            'credits_cents' => $credits, 'debits_cents' => $debits, 'ledger_balance_cents' => $ofx['ledger_balance_cents'], 'ledger_as_of' => $ofx['ledger_as_of'],
            'already' => count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'already')),
        ]];
    }

    /** Grava as linhas novas do extrato e concilia com os lançamentos pagos e as transferências da conta. */
    private function applyOfx(User $user, int $importId, array $bankAccount, array $result): array
    {
        $lines = array_values(array_map(static fn (array $r): array => $r['data'], array_filter($result['rows'], static fn (array $r): bool => $r['status'] === 'ok')));
        $counts = ['lines' => count($lines), 'matched' => 0, 'unmatched' => 0, 'already' => (int)$result['summary']['already']];
        if ($lines === []) {
            return $counts;
        }
        $dates = array_column($lines, 'posted_on');
        $from = (new \DateTimeImmutable(min($dates)))->modify('-' . ImportRules::MATCH_DAYS . ' days')->format('Y-m-d');
        $to = (new \DateTimeImmutable(max($dates)))->modify('+' . ImportRules::MATCH_DAYS . ' days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT e.id, e.nature, e.paid_on, e.amount_cents FROM erp_entries e LEFT JOIN erp_bank_statement_lines l ON l.entry_id = e.id
            WHERE e.bank_account_id = :b AND e.paid_on BETWEEN :f AND :t AND e.status = 'active' AND e.deleted_at IS NULL AND l.id IS NULL");
        $stmt->execute(['b' => $bankAccount['id'], 'f' => $from, 't' => $to]);
        $candidates = array_map(static fn (array $r): array => ['key' => 'e' . $r['id'], 'direction' => $r['nature'] === 'receita' ? 'in' : 'out',
            'on' => (string)$r['paid_on'], 'amount_cents' => (int)$r['amount_cents']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare("SELECT t.id, t.from_bank_account_id, t.transferred_on, t.amount_cents FROM erp_bank_transfers t
            LEFT JOIN erp_bank_statement_lines l ON l.transfer_id = t.id AND l.bank_account_id = :b2
            WHERE (t.from_bank_account_id = :b OR t.to_bank_account_id = :b1) AND t.status = 'active' AND t.transferred_on BETWEEN :f AND :t AND l.id IS NULL");
        $stmt->execute(['b' => $bankAccount['id'], 'b1' => $bankAccount['id'], 'b2' => $bankAccount['id'], 'f' => $from, 't' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $candidates[] = ['key' => 't' . $r['id'], 'direction' => (int)$r['from_bank_account_id'] === (int)$bankAccount['id'] ? 'out' : 'in',
                'on' => (string)$r['transferred_on'], 'amount_cents' => (int)$r['amount_cents']];
        }
        $matches = ImportRules::match($lines, $candidates);
        $insert = $this->pdo->prepare('INSERT INTO erp_bank_statement_lines (condominium_id, bank_account_id, import_id, fitid, kind, posted_on, amount_cents, memo, status, entry_id, transfer_id)
            VALUES (:c, :b, :i, :f, :k, :d, :v, :m, :s, :e, :t)');
        foreach ($lines as $line) {
            $key = $matches[$line['fitid']] ?? null;
            $insert->execute(['c' => $bankAccount['condominium_id'], 'b' => $bankAccount['id'], 'i' => $importId, 'f' => $line['fitid'], 'k' => $line['kind'],
                'd' => $line['posted_on'], 'v' => $line['amount_cents'], 'm' => $line['memo'], 's' => $key !== null ? 'matched' : 'unmatched',
                'e' => $key !== null && $key[0] === 'e' ? (int)substr($key, 1) : null, 't' => $key !== null && $key[0] === 't' ? (int)substr($key, 1) : null]);
            $counts[$key !== null ? 'matched' : 'unmatched']++;
        }
        return $counts;
    }

    /** @param list<array> $rows */
    private function applyEntries(User $user, int $importId, array $rows): array
    {
        $link = $this->pdo->prepare('UPDATE erp_entries SET import_id = :i WHERE id = :id');
        $count = 0;
        foreach ($rows as $row) {
            $entry = $this->registry->save($user, 'entries', $row['data']);
            $link->execute(['i' => $importId, 'id' => (int)$entry['id']]);
            $count++;
        }
        return ['entries' => $count];
    }

    /** @param list<array> $rows */
    private function applyFractions(User $user, array $rows): array
    {
        $before = $this->pdo->prepare('SELECT ideal_fraction FROM erp_units WHERE id = :id FOR UPDATE');
        $update = $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = :f, updated_by = :u WHERE id = :id');
        foreach ($rows as $row) {
            $before->execute(['id' => $row['data']['unit_id']]);
            $old = $before->fetchColumn();
            $update->execute(['f' => $row['data']['ideal_fraction'], 'u' => (int)$user->id, 'id' => $row['data']['unit_id']]);
            Audit::record('update', 'erp_units', (int)$row['data']['unit_id'], ['ideal_fraction' => $old], ['ideal_fraction' => $row['data']['ideal_fraction']]);
        }
        return ['units' => count($rows)];
    }

    /** Contas do plano por código (do condomínio antes do padrão), unidades por rótulo, bancos por nome, fornecedores por documento. */
    private function lookups(int $condominium): array
    {
        $accounts = [];
        $stmt = $this->pdo->prepare("SELECT id, code, nature FROM erp_chart_accounts WHERE deleted_at IS NULL AND status = 'active' AND kind = 'analitica'
            AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY condominium_id IS NULL");
        $stmt->execute(['c' => $condominium]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[(string)$row['code']] ??= ['id' => (int)$row['id'], 'code' => (string)$row['code'], 'nature' => (string)$row['nature']];
        }
        $units = [];
        $stmt = $this->pdo->prepare("SELECT id, label FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['c' => $condominium]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $units[mb_strtolower((string)$row['label'])] = (int)$row['id'];
        }
        $banks = [];
        $stmt = $this->pdo->prepare("SELECT id, name FROM erp_bank_accounts WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['c' => $condominium]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $banks[mb_strtolower(trim((string)$row['name']))] = (int)$row['id'];
        }
        $suppliers = [];
        foreach ($this->pdo->query("SELECT id, document FROM erp_suppliers WHERE deleted_at IS NULL AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $suppliers[(string)$row['document']] = (int)$row['id'];
        }
        return ['accounts' => $accounts, 'units' => $units, 'banks' => $banks, 'suppliers' => $suppliers];
    }

    /** @return array{0:string, 1:string} conteúdo e nome limpo */
    private function readUpload(array $upload, string $kind): array
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
        if (!in_array($extension, self::KINDS[$kind]['formats'], true)) {
            throw new InvalidArgumentException('Formato não aceito para esta importação: ' . implode(', ', self::KINDS[$kind]['formats']) . '.');
        }
        $content = (string)file_get_contents($temporary);
        if ($extension === 'xlsx' && !str_starts_with($content, "PK\x03\x04")) {
            throw new InvalidArgumentException('O arquivo não é um XLSX válido.');
        }
        if ($extension !== 'xlsx' && str_contains($content, "\0")) {
            throw new InvalidArgumentException('O arquivo não é texto (CSV ou OFX).');
        }
        return [$content, $name];
    }

    private function store(string $relative, string $content): void
    {
        $target = $this->root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar a pasta das importações.');
        }
        $base = dirname($this->root);
        if (is_dir($base) && !is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
        if (!is_file($target) && file_put_contents($target, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível guardar o arquivo da importação.');
        }
    }

    private function load(string $relative): string
    {
        if (!preg_match('/^\d+\/[a-f0-9]{64}\.(csv|txt|xlsx|ofx)$/', $relative) || !is_file($this->root . '/' . $relative)) {
            throw new DomainException('O arquivo da prévia não está mais guardado. Envie de novo.');
        }
        return (string)file_get_contents($this->root . '/' . $relative);
    }

    private function bankAccount(?int $id, int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, name, account_number FROM erp_bank_accounts WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => (int)$id, 'c' => $condominium]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Escolha uma conta bancária ativa deste condomínio para o extrato.');
        }
        return $row;
    }

    private function byHash(int $condominium, string $kind, string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status FROM erp_imports WHERE condominium_id = :c AND kind = :k AND sha256 = :h');
        $stmt->execute(['c' => $condominium, 'k' => $kind, 'h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function row(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, c.name AS condominium_name FROM erp_imports i JOIN condominiums c ON c.id = i.condominium_id WHERE i.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function present(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'kind' => (string)$r['kind'], 'kind_label' => self::KINDS[$r['kind']]['label'] ?? (string)$r['kind'], 'page' => self::KINDS[$r['kind']]['page'] ?? null,
            'bank_account_id' => $r['bank_account_id'] !== null ? (int)$r['bank_account_id'] : null, 'original_name' => (string)$r['original_name'],
            'sha256' => (string)$r['sha256'], 'size_bytes' => (int)$r['size_bytes'], 'status' => (string)$r['status'],
            'rows_count' => (int)$r['rows_count'], 'error_count' => (int)$r['error_count'],
            'summary' => json_decode((string)($r['summary_json'] ?? ''), true) ?: null,
            'created_at' => (string)$r['created_at'], 'applied_at' => $r['applied_at'],
        ];
    }

    private function presentLine(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'fitid' => (string)$r['fitid'], 'kind' => (string)$r['kind'], 'posted_on' => (string)$r['posted_on'],
            'amount_cents' => (int)$r['amount_cents'], 'memo' => $r['memo'], 'status' => (string)$r['status'],
            'entry_id' => $r['entry_id'] !== null ? (int)$r['entry_id'] : null, 'transfer_id' => $r['transfer_id'] !== null ? (int)$r['transfer_id'] : null,
            'note' => $r['note'],
        ];
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
