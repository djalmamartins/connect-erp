<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\ImportRules;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;
use Source\Support\AuditMask;

/**
 * Configurações do ERP (ADR 0085). Três partes:
 *
 * - Parâmetros por chave (CATALOG): padrão do código → padrão da Connect (condominium_id NULL) → valor
 *   do condomínio. Chave 'global' só tem valor da Connect (e só quem acessa todos os condomínios
 *   altera). Os padrões são os de hoje. Nenhuma chave guarda credencial: banco e SMTP vêm do ambiente
 *   (ADR 0060), e safeKey() recusa nome com cara de segredo.
 * - Saldo inicial por centro de custo (AP-162) e previsão orçamentária (AP-163), com o comparativo do
 *   previsto contra o lançado por competência (base do AP-084).
 * - Frações ideais em lote (AP-167): todas as unidades ativas, soma 1 dentro da tolerância.
 *
 * Multa, juros, índice e quebra seguem nas regras de cobrança (AP-165, ADR 0040); o plano de contas
 * (AP-160) e os tipos de fatura (AP-168) têm cadastro próprio. Toda alteração é auditada.
 */
final class ErpSettings
{
    /**
     * key => [página, grupo, rótulo, tipo, padrão, escopo, limites]. tipo: int|bool|money|decimal|text|email|enum.
     * escopo: condominium (a Connect define o padrão e o condomínio pode mudar) ou global (só a Connect).
     */
    public const CATALOG = [
        'admin.support_email' => ['page' => 'AP-147', 'group' => 'Administrativo', 'label' => 'E-mail de atendimento da administradora', 'type' => 'email', 'default' => '', 'scope' => 'global'],
        'admin.support_phone' => ['page' => 'AP-147', 'group' => 'Administrativo', 'label' => 'Telefone de atendimento', 'type' => 'text', 'default' => '', 'scope' => 'global', 'max' => 20],
        'closing.notify_board' => ['page' => 'AP-151', 'group' => 'Notificações da prestação de contas', 'label' => 'Avisar o conselho quando o mês for fechado', 'type' => 'bool', 'default' => false, 'scope' => 'condominium'],
        'app.display_name' => ['page' => 'AP-154', 'group' => 'Layout da aplicação', 'label' => 'Nome exibido no aplicativo', 'type' => 'text', 'default' => 'Connect', 'scope' => 'global', 'min' => 2, 'max' => 80],
        'app.notices_enabled' => ['page' => 'AP-155', 'group' => 'Notificações do aplicativo', 'label' => 'Enviar comunicados por e-mail', 'type' => 'bool', 'default' => true, 'scope' => 'condominium'],
        'app.collection_cooldown_days' => ['page' => 'AP-155', 'group' => 'Notificações do aplicativo', 'label' => 'Dias entre avisos de cobrança da mesma unidade', 'type' => 'int', 'default' => 7, 'scope' => 'global', 'min' => 1, 'max' => 90],
        'finance.default_due_day' => ['page' => 'AP-157', 'group' => 'Financeiro', 'label' => 'Dia de vencimento padrão das faturas', 'type' => 'int', 'default' => 10, 'scope' => 'condominium', 'min' => 1, 'max' => 28],
        'statement.show_open_entries' => ['page' => 'AP-158', 'group' => 'Demonstrativo', 'label' => 'Mostrar contas em aberto no demonstrativo', 'type' => 'bool', 'default' => true, 'scope' => 'condominium'],
        'statement.group_by' => ['page' => 'AP-158', 'group' => 'Demonstrativo', 'label' => 'Agrupar o demonstrativo por', 'type' => 'enum', 'default' => 'chart', 'scope' => 'condominium',
            'options' => ['chart' => 'Conta do plano', 'cost_center' => 'Centro de custo']],
        'alerts.overdue_days' => ['page' => 'AP-159', 'group' => 'Alertas', 'label' => 'Alertar unidade em atraso há mais de (dias)', 'type' => 'int', 'default' => 30, 'scope' => 'condominium', 'min' => 1, 'max' => 365],
        'alerts.contract_expiry_days' => ['page' => 'AP-159', 'group' => 'Alertas', 'label' => 'Alertar contrato e seguro que vencem em (dias)', 'type' => 'int', 'default' => 30, 'scope' => 'condominium', 'min' => 1, 'max' => 365],
        'alerts.low_balance_cents' => ['page' => 'AP-159', 'group' => 'Alertas', 'label' => 'Alertar conta bancária com saldo abaixo de', 'type' => 'money', 'default' => 0, 'scope' => 'condominium'],
        'params.fraction_tolerance' => ['page' => 'AP-166', 'group' => 'Parâmetros', 'label' => 'Tolerância da soma das frações ideais', 'type' => 'decimal', 'default' => '0.0001', 'scope' => 'global', 'min' => 0, 'max' => 0.01],
        'params.report_max_rows' => ['page' => 'AP-166', 'group' => 'Parâmetros', 'label' => 'Máximo de linhas por relatório', 'type' => 'int', 'default' => 5000, 'scope' => 'global', 'min' => 100, 'max' => 20000],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Nome de chave sem cara de credencial (regra pura): banco, SMTP, senha e tokens vêm do ambiente. */
    public static function safeKey(string $key): bool
    {
        return (bool)preg_match('/^[a-z]+\.[a-z_]+$/', $key) && !preg_match('/pass|secret|token|smtp|api_?key|credential|agencia|conta_?bancaria|bank_account/i', $key);
    }

    /**
     * Valor validado e normalizado (regra pura) conforme o tipo da chave.
     *
     * @return int|bool|string
     */
    public static function normalize(string $key, mixed $value): int|bool|string
    {
        $spec = self::CATALOG[$key] ?? throw new InvalidArgumentException('Configuração desconhecida.');
        $fail = static fn (): InvalidArgumentException => new InvalidArgumentException("Valor inválido para \"{$spec['label']}\".");
        switch ($spec['type']) {
            case 'bool':
                $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                return $bool ?? throw $fail();
            case 'int':
                $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $spec['min'], 'max_range' => $spec['max']]]);
                return $int === false ? throw $fail() : (int)$int;
            case 'money':
                $cents = is_int($value) ? $value : (($value === '0' || $value === '0,00') ? 0 : Invoices::cents($value));
                return $cents === null || $cents < 0 || $cents > 100_000_000_00 ? throw $fail() : $cents;
            case 'decimal':
                $text = str_replace(',', '.', trim((string)$value));
                if (!preg_match('/^\d+(\.\d{1,6})?$/', $text) || (float)$text < $spec['min'] || (float)$text > $spec['max']) {
                    throw $fail();
                }
                return $text;
            case 'enum':
                return is_string($value) && isset($spec['options'][$value]) ? $value : throw $fail();
            case 'email':
                $text = is_string($value) ? trim($value) : '';
                return $text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) ? mb_strtolower($text) : throw $fail();
            default:
                $text = is_string($value) ? trim((string)preg_replace('/[\p{C}]+/u', '', $value)) : '';
                $length = mb_strlen($text);
                return $length < ($spec['min'] ?? 0) || $length > ($spec['max'] ?? 160) ? throw $fail() : $text;
        }
    }

    /** Valor efetivo de uma chave para o condomínio (null = padrão da Connect), sem checar usuário. */
    public static function value(PDO $pdo, ?int $condominiumId, string $key): int|bool|string
    {
        $spec = self::CATALOG[$key] ?? throw new InvalidArgumentException('Configuração desconhecida.');
        $stmt = $pdo->prepare('SELECT condominium_id, value_json FROM erp_settings WHERE setting_key = :k AND (condominium_id IS NULL' . ($condominiumId !== null && $spec['scope'] === 'condominium' ? ' OR condominium_id = :c' : '') . ')
            ORDER BY condominium_id IS NULL');
        $stmt->execute(['k' => $key] + ($condominiumId !== null && $spec['scope'] === 'condominium' ? ['c' => $condominiumId] : []));
        $raw = $stmt->fetchColumn(1);
        return $raw === false ? $spec['default'] : json_decode((string)$raw, true);
    }

    /** Parâmetros agrupados por página, com o valor efetivo e de onde vem (code, connect, condominium). */
    public function list(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
        }
        $rows = $this->pdo->prepare('SELECT condominium_id, setting_key, value_json, updated_at FROM erp_settings WHERE condominium_id IS NULL' . ($condominiumId !== null ? ' OR condominium_id = :c' : ''));
        $rows->execute($condominiumId !== null ? ['c' => $condominiumId] : []);
        $stored = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[$row['setting_key']][$row['condominium_id'] === null ? 'connect' : 'condominium'] = ['value' => json_decode((string)$row['value_json'], true), 'updated_at' => $row['updated_at']];
        }
        $groups = [];
        foreach (self::CATALOG as $key => $spec) {
            $own = $spec['scope'] === 'condominium' ? ($stored[$key]['condominium'] ?? null) : null;
            $connect = $stored[$key]['connect'] ?? null;
            $source = $own !== null ? 'condominium' : ($connect !== null ? 'connect' : 'code');
            $groups[$spec['page']] ??= ['page' => $spec['page'], 'group' => $spec['group'], 'settings' => []];
            $groups[$spec['page']]['settings'][] = ['key' => $key, 'label' => $spec['label'], 'type' => $spec['type'], 'scope' => $spec['scope'],
                'options' => $spec['options'] ?? null, 'min' => $spec['min'] ?? null, 'max' => $spec['max'] ?? null, 'default' => $spec['default'],
                'value' => ($own ?? $connect)['value'] ?? $spec['default'], 'source' => $source,
                'editable_here' => $condominiumId === null || $spec['scope'] === 'condominium'];
        }
        return ['condominium_id' => $condominiumId, 'groups' => array_values($groups)];
    }

    /** Altera uma chave (condomínio, ou o padrão da Connect com $condominiumId null), com auditoria. */
    public function set(User $user, ?int $condominiumId, string $key, mixed $value): array
    {
        $spec = self::CATALOG[$key] ?? throw new InvalidArgumentException('Configuração desconhecida.');
        $this->assertWritable($user, $condominiumId, $spec);
        $normalized = self::normalize($key, $value);
        $before = self::value($this->pdo, $condominiumId, $key);
        $this->pdo->prepare('INSERT INTO erp_settings (condominium_id, setting_key, value_json, updated_by) VALUES (:c, :k, :v, :u)
            ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by = VALUES(updated_by)')
            ->execute(['c' => $condominiumId, 'k' => $key, 'v' => json_encode($normalized, JSON_UNESCAPED_UNICODE), 'u' => (int)$user->id]);
        Audit::record('update', 'erp_settings', $condominiumId ?? 0, ['key' => $key, 'value' => self::auditValue($spec, $before)],
            ['key' => $key, 'value' => self::auditValue($spec, $normalized), 'condominium_id' => $condominiumId]);
        return $this->list($user, $condominiumId);
    }

    /** ADR 0096: na auditoria, o valor passa pelo AuditMask conforme o tipo (e-mail e telefone mascarados). */
    public static function auditValue(array $spec, mixed $value): mixed
    {
        $value = AuditMask::fields(['value' => $value], ['value' => ['type' => (string)($spec['type'] ?? 'text')]])['value'];
        // ADR 0097: texto livre também passa pelo AuditMask::text (CPF, CNPJ, e-mail e telefone no meio do texto).
        return is_string($value) ? AuditMask::text($value) : $value;
    }

    /** Volta a chave ao padrão de cima (do condomínio → Connect; da Connect → código), com auditoria. */
    public function reset(User $user, ?int $condominiumId, string $key): array
    {
        $spec = self::CATALOG[$key] ?? throw new InvalidArgumentException('Configuração desconhecida.');
        $this->assertWritable($user, $condominiumId, $spec);
        $before = self::value($this->pdo, $condominiumId, $key);
        $stmt = $this->pdo->prepare('DELETE FROM erp_settings WHERE setting_key = :k AND condominium_id ' . ($condominiumId === null ? 'IS NULL' : '= :c'));
        $stmt->execute(['k' => $key] + ($condominiumId === null ? [] : ['c' => $condominiumId]));
        if ($stmt->rowCount() > 0) {
            Audit::record('update', 'erp_settings', $condominiumId ?? 0, ['key' => $key, 'value' => self::auditValue($spec, $before)], ['key' => $key, 'reset' => true, 'condominium_id' => $condominiumId]);
        }
        return $this->list($user, $condominiumId);
    }

    /** AP-162: saldo inicial (com sinal) de um centro de custo do condomínio ou do padrão, numa data. */
    public function setOpening(User $user, int $condominiumId, int $costCenterId, mixed $openedOn, mixed $amount, mixed $notes = null): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $center = $this->pdo->prepare("SELECT id FROM erp_cost_centers WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND (condominium_id IS NULL OR condominium_id = :c)");
        $center->execute(['id' => $costCenterId, 'c' => $condominiumId]);
        if ($center->fetchColumn() === false) {
            throw new InvalidArgumentException('Escolha um centro de custo ativo do padrão ou deste condomínio.');
        }
        $date = is_string($openedOn) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $openedOn) : false;
        if ($date === false || $date->format('Y-m-d') !== $openedOn || $openedOn > date('Y-m-d')) {
            throw new InvalidArgumentException('Data do saldo inicial inválida (AAAA-MM-DD, até hoje).');
        }
        $cents = self::signedCents($amount);
        $note = is_string($notes) && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null;
        $before = $this->pdo->prepare('SELECT amount_cents, opened_on FROM erp_cost_center_openings WHERE condominium_id = :c AND cost_center_id = :cc');
        $before->execute(['c' => $condominiumId, 'cc' => $costCenterId]);
        $old = $before->fetch(PDO::FETCH_ASSOC) ?: null;
        $this->pdo->prepare('INSERT INTO erp_cost_center_openings (condominium_id, cost_center_id, opened_on, amount_cents, notes, updated_by) VALUES (:c, :cc, :d, :v, :n, :u)
            ON DUPLICATE KEY UPDATE opened_on = VALUES(opened_on), amount_cents = VALUES(amount_cents), notes = VALUES(notes), updated_by = VALUES(updated_by)')
            ->execute(['c' => $condominiumId, 'cc' => $costCenterId, 'd' => $openedOn, 'v' => $cents, 'n' => $note, 'u' => (int)$user->id]);
        Audit::record('update', 'erp_cost_center_openings', $costCenterId, $old, ['condominium_id' => $condominiumId, 'opened_on' => $openedOn, 'amount_cents' => $cents]);
        return $this->openings($user, $condominiumId);
    }

    public function openings(User $user, int $condominiumId): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT o.cost_center_id, cc.code, cc.name, o.opened_on, o.amount_cents, o.notes, o.updated_at FROM erp_cost_center_openings o
            JOIN erp_cost_centers cc ON cc.id = o.cost_center_id WHERE o.condominium_id = :c ORDER BY cc.code');
        $stmt->execute(['c' => $condominiumId]);
        $rows = array_map(static fn (array $r): array => ['cost_center_id' => (int)$r['cost_center_id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
            'opened_on' => (string)$r['opened_on'], 'amount_cents' => (int)$r['amount_cents'], 'notes' => $r['notes'], 'updated_at' => (string)$r['updated_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['condominium_id' => $condominiumId, 'rows' => $rows, 'total_cents' => array_sum(array_column($rows, 'amount_cents'))];
    }

    /**
     * AP-163: previsto de uma conta analítica no ano, mês a mês (12 valores; vazio = 0). Substitui os 12
     * meses da conta naquele ano. Competência fechada não muda (ADR 0031).
     *
     * @param list<mixed> $months
     */
    public function setBudget(User $user, int $condominiumId, int $year, int $accountId, array $months): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        if ($year < 2000 || $year > (int)date('Y') + 5) {
            throw new InvalidArgumentException('Ano inválido.');
        }
        if (count($months) !== 12) {
            throw new InvalidArgumentException('Informe os 12 meses (vazio = zero).');
        }
        $account = $this->pdo->prepare("SELECT id FROM erp_chart_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND kind = 'analitica'
            AND (condominium_id IS NULL OR condominium_id = :c)");
        $account->execute(['id' => $accountId, 'c' => $condominiumId]);
        if ($account->fetchColumn() === false) {
            throw new InvalidArgumentException('Escolha uma conta analítica ativa do padrão ou deste condomínio.');
        }
        $values = [];
        foreach (array_values($months) as $index => $raw) {
            $cents = $raw === null || $raw === '' || $raw === 0 || $raw === '0' ? 0 : Invoices::cents($raw);
            if ($cents === null) {
                throw new InvalidArgumentException(sprintf('Valor inválido no mês %d.', $index + 1));
            }
            $values[$index + 1] = $cents;
        }
        $registry = new Registry\RegistryService($this->pdo, $this->scope);
        $existing = $this->pdo->prepare('SELECT month, amount_cents FROM erp_budget_lines WHERE condominium_id = :c AND year = :y AND chart_account_id = :a');
        $existing->execute(['c' => $condominiumId, 'y' => $year, 'a' => $accountId]);
        $before = array_map('intval', $existing->fetchAll(PDO::FETCH_KEY_PAIR));
        foreach ($values as $month => $cents) {
            if (($before[$month] ?? 0) !== $cents && $registry->isClosed($condominiumId, sprintf('%04d-%02d', $year, $month))) {
                throw new DomainException(sprintf('A competência %04d-%02d está fechada; o previsto dela não muda.', $year, $month));
            }
        }
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare('INSERT INTO erp_budget_lines (condominium_id, year, chart_account_id, month, amount_cents, updated_by) VALUES (:c, :y, :a, :m, :v, :u)
                ON DUPLICATE KEY UPDATE amount_cents = VALUES(amount_cents), updated_by = VALUES(updated_by)');
            foreach ($values as $month => $cents) {
                $upsert->execute(['c' => $condominiumId, 'y' => $year, 'a' => $accountId, 'm' => $month, 'v' => $cents, 'u' => (int)$user->id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_budget_lines', $accountId, ['year' => $year, 'months' => $before], ['condominium_id' => $condominiumId, 'year' => $year, 'months' => $values]);
        return $this->budget($user, $condominiumId, $year);
    }

    /** AP-163/AP-084: previsto por conta e mês, com o lançado (competência) e a diferença. */
    public function budget(User $user, int $condominiumId, int $year): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT b.chart_account_id, a.code, a.name, a.nature, b.month, b.amount_cents FROM erp_budget_lines b JOIN erp_chart_accounts a ON a.id = b.chart_account_id
            WHERE b.condominium_id = :c AND b.year = :y ORDER BY a.code, b.month');
        $stmt->execute(['c' => $condominiumId, 'y' => $year]);
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['chart_account_id'];
            $accounts[$id] ??= ['chart_account_id' => $id, 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'],
                'budget' => array_fill(1, 12, 0), 'actual' => array_fill(1, 12, 0)];
            $accounts[$id]['budget'][(int)$r['month']] = (int)$r['amount_cents'];
        }
        if ($accounts !== []) {
            $actual = $this->pdo->prepare("SELECT chart_account_id, CAST(SUBSTRING(competence, 6, 2) AS UNSIGNED) AS month, SUM(amount_cents) AS total FROM erp_entries
                WHERE condominium_id = :c AND competence LIKE :y AND status = 'active' AND deleted_at IS NULL AND chart_account_id IN (" . implode(',', array_keys($accounts)) . ')
                GROUP BY chart_account_id, month');
            $actual->execute(['c' => $condominiumId, 'y' => sprintf('%04d-%%', $year)]);
            foreach ($actual->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $accounts[(int)$r['chart_account_id']]['actual'][(int)$r['month']] = (int)$r['total'];
            }
        }
        $rows = array_map(static fn (array $a): array => $a + ['budget_total' => array_sum($a['budget']), 'actual_total' => array_sum($a['actual'])]
            + ManagementReports::variation(array_sum($a['budget']), array_sum($a['actual'])), array_values($accounts));
        return ['condominium_id' => $condominiumId, 'year' => $year, 'rows' => $rows];
    }

    /**
     * AP-167: frações ideais em lote ([unit_id => fração]). Todas as unidades ativas, sem faltar
     * nenhuma, soma 1 dentro da tolerância (params.fraction_tolerance). Tudo ou nada, auditado.
     *
     * @param array<int|string, mixed> $fractions
     */
    public function setFractions(User $user, int $condominiumId, array $fractions): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        // ADR 0096: as unidades são lidas e travadas dentro da transação; ninguém cria nem inativa unidade
        // entre a conferência da soma e a gravação.
        $this->pdo->beginTransaction();
        try {
            $result = $this->applyFractions($user, $condominiumId, $fractions);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $result;
    }

    /** Dentro da transação de setFractions: confere todas as unidades ativas e a soma, e grava auditando cada uma. */
    private function applyFractions(User $user, int $condominiumId, array $fractions): array
    {
        $stmt = $this->pdo->prepare("SELECT id, label, ideal_fraction FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' FOR UPDATE");
        $stmt->execute(['c' => $condominiumId]);
        $units = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $units[(int)$u['id']] = $u;
        }
        $clean = [];
        foreach ($fractions as $unitId => $raw) {
            $id = filter_var($unitId, FILTER_VALIDATE_INT);
            if ($id === false || !isset($units[(int)$id])) {
                throw new InvalidArgumentException('Há unidade que não é ativa deste condomínio.');
            }
            $value = ImportRules::fraction((string)$raw);
            if ($value === null) {
                throw new InvalidArgumentException(sprintf('Fração inválida na unidade %s.', $units[(int)$id]['label']));
            }
            $clean[(int)$id] = $value;
        }
        $missing = array_diff(array_keys($units), array_keys($clean));
        if ($units === [] || $missing !== []) {
            throw new InvalidArgumentException(sprintf('Informe a fração de todas as unidades ativas (faltam %d).', count($missing)));
        }
        $sum = array_sum(array_map('floatval', $clean));
        $tolerance = (float)self::value($this->pdo, null, 'params.fraction_tolerance');
        if (abs($sum - 1) > $tolerance) {
            throw new InvalidArgumentException(sprintf('A soma das frações é %s; precisa ser 1 (100%%).', number_format($sum, 6, ',', '')));
        }
        $update = $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = :f, updated_by = :u WHERE id = :id AND condominium_id = :c');
        foreach ($clean as $id => $value) {
            $update->execute(['f' => $value, 'u' => (int)$user->id, 'id' => $id, 'c' => $condominiumId]);
            Audit::record('update', 'erp_units', $id, ['ideal_fraction' => $units[$id]['ideal_fraction']], ['ideal_fraction' => $value]);
        }
        return ['condominium_id' => $condominiumId, 'units' => count($clean), 'fraction_sum' => round($sum, 6)];
    }

    /** Valor com sinal (regra pura): "-1.234,56", "1234,56" ou inteiro em centavos. */
    public static function signedCents(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $text = is_string($value) ? trim($value) : '';
        $negative = str_starts_with($text, '-');
        $cents = $text === '0' || $text === '0,00' ? 0 : Invoices::cents(ltrim($text, '-'));
        if ($cents === null) {
            throw new InvalidArgumentException('Valor inválido (ex.: 1.234,56 ou -1.234,56).');
        }
        return $negative ? -$cents : $cents;
    }

    private function assertWritable(User $user, ?int $condominiumId, array $spec): void
    {
        if ($condominiumId === null) {
            if (!$this->scope->hasGlobalAccess($user)) {
                throw new RegistryForbidden();   // o padrão da Connect vale para todos: só quem acessa todos altera
            }
            return;
        }
        if ($spec['scope'] !== 'condominium') {
            throw new InvalidArgumentException('Esta configuração é só da Connect, não por condomínio.');
        }
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
    }
}
