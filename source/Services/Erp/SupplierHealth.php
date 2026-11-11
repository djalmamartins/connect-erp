<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência do cadastro de fornecedores (ADR 0200), somente leitura. O cadastro de fornecedores é da Connect, comum a
 * todos os condomínios (Definitions 'suppliers', scope none), e acumula problemas com o tempo (importações, cadastros às
 * pressas, fornecedores trocados). Fornecedores não excluídos:
 *
 * - invalid_document: CPF ou CNPJ com os dígitos verificadores errados (InvitationRules::validDocument);
 * - similar_names: grupos de fornecedores com o mesmo nome normalizado (regra pura normalizeName: sem acento, pontuação,
 *   caixa e sufixos como LTDA, ME, EPP, EIRELI, S/A), provável cadastro em dobro com documento diferente ou digitado errado;
 * - inactive_in_use: fornecedor inativo com contas a pagar em aberto nos condomínios do usuário;
 * - no_payment_data: fornecedor ativo pago nos últimos 12 meses nos condomínios do usuário, sem chave Pix e sem conta
 *   bancária (só a marca; os dados bancários não saem);
 * - dormant: fornecedor ativo, cadastrado há mais de 24 meses, sem nenhum lançamento nesse tempo (candidato a inativar). Precisa olhar todos os
 *   condomínios: só com acesso a todos; senão vem null e o aviso partial_scope.
 *
 * Do fornecedor saem id, nome, nome fantasia, categoria, situação e o documento mascarado; e-mail, telefone, banco e Pix
 * não saem. Contas a pagar só dos condomínios do escopo do usuário. Cada lista vem limitada a LIMIT, com a marca de corte.
 */
final class SupplierHealth
{
    public const LIMIT = 200;
    public const SUFFIXES = ['ltda', 'me', 'epp', 'eireli', 'sa', 'mei', 'cia', 'limitada', 'ss'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Nome normalizado para achar cadastro em dobro (regra pura). */
    public static function normalizeName(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        $text = str_replace(['s/a', 's.a.', 's.a'], ' sa ', $text);
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($words !== [] && in_array(end($words), self::SUFFIXES, true)) {
            array_pop($words);
        }
        return implode(' ', $words);
    }

    public function build(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $global = $this->scope->hasGlobalAccess($user);
        $ids = $this->scope->allowedIds($user);

        $rows = $this->pdo->query("SELECT id, legal_name, trade_name, document, category, status, pix_key, bank_account, created_at FROM erp_suppliers
            WHERE deleted_at IS NULL ORDER BY legal_name, id")->fetchAll(PDO::FETCH_ASSOC);
        $suppliers = [];
        foreach ($rows as $r) {
            $suppliers[(int)$r['id']] = $r;
        }
        $present = static fn (array $s): array => ['supplier_id' => (int)$s['id'], 'legal_name' => (string)$s['legal_name'],
            'trade_name' => $s['trade_name'] === null || $s['trade_name'] === '' ? null : (string)$s['trade_name'], 'category' => (string)$s['category'],
            'status' => (string)$s['status'], 'document_masked' => CollectionReports::maskDocument($s['document'])];

        $sections = ['invalid_document' => [], 'similar_names' => [], 'inactive_in_use' => [], 'no_payment_data' => []];
        $groups = [];
        foreach ($suppliers as $s) {
            if (!InvitationRules::validDocument($s['document'])) {
                $sections['invalid_document'][] = $present($s);
            }
            $key = self::normalizeName((string)$s['legal_name']);
            if ($key !== '') {
                $groups[$key][] = $present($s);
            }
        }
        foreach ($groups as $key => $list) {
            if (count($list) > 1) {
                usort($list, static fn (array $a, array $b): int => $a['supplier_id'] <=> $b['supplier_id']);   // ordem estável em qualquer collation
                $sections['similar_names'][] = ['normalized_name' => $key, 'suppliers' => $list];
            }
        }

        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $open = $this->pdo->prepare("SELECT e.supplier_id, COUNT(*) AS entries, SUM(e.amount_cents) AS cents FROM erp_entries e JOIN erp_suppliers s ON s.id = e.supplier_id
                WHERE e.condominium_id IN ({$marks}) AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on IS NULL
                  AND s.status = 'inactive' AND s.deleted_at IS NULL GROUP BY e.supplier_id");
            $open->execute($ids);
            foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sections['inactive_in_use'][] = $present($suppliers[(int)$r['supplier_id']]) + ['open_entries' => (int)$r['entries'], 'open_cents' => (int)$r['cents']];
            }
            $since = (new \DateTimeImmutable($today))->modify('-12 months')->format('Y-m-d');
            $paid = $this->pdo->prepare("SELECT DISTINCT e.supplier_id FROM erp_entries e
                WHERE e.condominium_id IN ({$marks}) AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.supplier_id IS NOT NULL
                  AND e.paid_on IS NOT NULL AND e.paid_on >= ?");
            $paid->execute(array_merge($ids, [$since]));
            foreach (array_map('intval', $paid->fetchAll(PDO::FETCH_COLUMN)) as $id) {
                $s = $suppliers[$id] ?? null;
                if ($s !== null && $s['status'] === 'active' && trim((string)$s['pix_key']) === '' && trim((string)$s['bank_account']) === '') {
                    $sections['no_payment_data'][] = $present($s);
                }
            }
            usort($sections['inactive_in_use'], static fn (array $a, array $b): int => [$a['legal_name'], $a['supplier_id']] <=> [$b['legal_name'], $b['supplier_id']]);
            usort($sections['no_payment_data'], static fn (array $a, array $b): int => [$a['legal_name'], $a['supplier_id']] <=> [$b['legal_name'], $b['supplier_id']]);
        }

        $warnings = [];
        $dormant = null;
        if ($global) {
            $cut = (new \DateTimeImmutable($today))->modify('-24 months')->format('Y-m-d');
            $used = $this->pdo->prepare('SELECT DISTINCT supplier_id FROM erp_entries WHERE supplier_id IS NOT NULL AND deleted_at IS NULL AND due_on >= ?');
            $used->execute([$cut]);
            $usedIds = array_fill_keys(array_map('intval', $used->fetchAll(PDO::FETCH_COLUMN)), true);
            $dormant = [];
            foreach ($suppliers as $id => $s) {
                if ($s['status'] === 'active' && !isset($usedIds[$id]) && substr((string)$s['created_at'], 0, 10) < $cut) {   // cadastrado há pouco não conta
                    $dormant[] = $present($s);
                }
            }
            $sections['dormant'] = $dormant;
        } else {
            $warnings[] = ['code' => 'partial_scope', 'message' => 'Fornecedor sem uso só aparece para quem acessa todos os condomínios; as contas a pagar são só dos seus.'];
        }

        $out = ['as_of' => $today, 'global_scope' => $global, 'condominiums' => count($ids), 'suppliers' => count($suppliers),
            'active_suppliers' => count(array_filter($suppliers, static fn (array $s): bool => $s['status'] === 'active')), 'summary' => [], 'warnings' => $warnings];
        foreach ($sections as $key => $items) {
            $out['summary'][$key] = count($items);
            $out[$key] = ['total' => count($items), 'truncated' => count($items) > self::LIMIT, 'items' => array_slice($items, 0, self::LIMIT)];
        }
        if (!$global) {
            $out['summary']['dormant'] = null;
            $out['dormant'] = null;
        }
        return $out;
    }
}
