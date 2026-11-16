<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Nova Receita (AP-025, revisão de 15/11 item 16, ADR 0313), com as opções do modelo 8 sobre os serviços que já existem:
 *
 * - Rateio: `none` (receita do condomínio, sem unidade: um lançamento pelo motor de cadastros), `all` (todas as
 *   unidades ativas, menos as isentas de rateio, ADR 0311) ou `chosen` (as unidades marcadas). Com rateio, é uma
 *   cobrança extra (EntryShortcuts::createCharge): valor fixo por unidade, total dividido, pela fração ou o valor de
 *   cada unidade (tabela).
 * - Fundo de reserva: percentual (0,01 a 30%) sobre o valor de cada unidade, numa segunda cobrança com a conta do fundo.
 * - Cobrar de: o responsável pela cobrança da unidade (padrão, o boleto decide), o proprietário ou o inquilino ativo,
 *   gravado como pagador da conta (ADR 0311). Sem a pessoa no papel escolhido, a conta fica com o padrão.
 * - Repetir: de 1 a 24 vezes, um mês depois do outro (competência e vencimento), com "(1/N)" na descrição se pedido.
 * - Competência = "Incluir na fatura de". Tudo numa transação: erro em qualquer mês desfaz todos.
 *
 * - Anexos no mesmo envio (ADR 0323): a tela manda cada arquivo depois do lançamento para todas as contas geradas
 *   (POST /api/v1/entry-attachments, EntryAttachments::attachMany), uma cópia só no Disco Virtual.
 *
 * - Conta relacionada (ADR 0329, INFERIDO do modelo): conta analítica do plano (do padrão ou do condomínio) que a receita
 *   cobre, gravada em todas as contas principais (as do fundo de reserva ficam sem ela).
 * - Índices da repetição (ADR 0329): "(i/N)" começa no índice inicial e termina no total, para continuar uma série
 *   já começada (ex.: 4/10 a 10/10). Sem eles, 1 até o número de meses.
 *
 * - Tipo da fatura (ADR 0331): com rateio, as contas (e as do fundo) ficam marcadas com o tipo escolhido e entram no
 *   boleto da fatura da unidade daquele tipo e competência quando ele for emitido (BankCharges). Vazio = boleto próprio.
 */
final class RevenueLaunch
{
    public const APPORTION = ['none' => 'Sem rateio (receita do condomínio)', 'all' => 'Cobrar de todas as unidades', 'chosen' => 'Cobrar das unidades escolhidas'];
    public const PAYERS = ['responsible' => 'Responsável pela cobrança da unidade', 'owner' => 'Proprietário', 'tenant' => 'Inquilino'];
    public const MAX_REPEAT = 24;
    public const MAX_INDEX = 999;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;
    private EntryShortcuts $shortcuts;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
        $this->shortcuts = new EntryShortcuts($this->pdo, $this->scope, $this->registry);
    }

    /** Mês $n depois (regra pura): competência AAAA-MM e vencimento no mesmo dia, ou no último dia do mês. @return array{0:string, 1:string} */
    public static function shift(string $competence, string $dueOn, int $n): array
    {
        $month = (new \DateTimeImmutable($competence . '-01'))->modify("+{$n} month")->format('Y-m');
        $due = new \DateTimeImmutable($dueOn);
        $first = $due->modify('first day of this month')->modify("+{$n} month");
        $day = min((int)$due->format('d'), (int)$first->format('t'));
        return [$month, $first->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT)];
    }

    /** Percentual do fundo de reserva (regra pura): vazio = sem fundo; 0,01 a 30. */
    public static function reservePercent(mixed $value): ?float
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? str_replace(',', '.', trim((string)$value)) : '';
        if ($text === '' || $text === '0') {
            return null;
        }
        if (!is_numeric($text) || (float)$text <= 0 || (float)$text > 30) {
            throw new InvalidArgumentException('Informe o fundo de reserva de 0,01% a 30%.');
        }
        return round((float)$text, 2);
    }

    /**
     * Índices da repetição (regra pura): inicial padrão 1; total padrão = inicial + meses - 1 e nunca menor que isso.
     * @return array{0:int, 1:int}
     */
    public static function indexes(mixed $start, mixed $total, int $repeat): array
    {
        $first = self::optionalInt($start) ?? 1;
        if ($first < 1 || $first > self::MAX_INDEX) {
            throw new InvalidArgumentException('O índice inicial da repetição vai de 1 a ' . self::MAX_INDEX . '.');
        }
        $last = $first + $repeat - 1;
        $all = self::optionalInt($total) ?? $last;
        if ($all < $last || $all > self::MAX_INDEX) {
            throw new InvalidArgumentException("O total de repetições precisa ser de {$last} a " . self::MAX_INDEX . ' (o índice inicial mais os meses lançados).');
        }
        return [$first, $all];
    }

    private static function optionalInt(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $int = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new InvalidArgumentException('Os índices da repetição precisam ser números inteiros.');
        }
        return $int;
    }

    /**
     * @return array{entries:list<int>, charges:list<int>, months:int}
     */
    public function launch(User $user, array $input): array
    {
        $condominium = (int)(filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if ($condominium < 1 || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        $apportion = is_string($input['apportion'] ?? null) && isset(self::APPORTION[$input['apportion']]) ? $input['apportion'] : null;
        if ($apportion === null) {
            throw new InvalidArgumentException('Escolha o rateio: none, all ou chosen.');
        }
        $payer = is_string($input['payer'] ?? null) && isset(self::PAYERS[$input['payer']]) ? $input['payer'] : 'responsible';
        $repeat = filter_var($input['repeat'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_REPEAT]]);
        if ($repeat === false) {
            throw new InvalidArgumentException('Repita de 1 a ' . self::MAX_REPEAT . ' vezes.');
        }
        [$firstIndex, $totalIndex] = self::indexes($input['repeat_start'] ?? null, $input['repeat_total'] ?? null, $repeat);
        $label = !empty($input['repeat_label']) && $totalIndex > 1;
        $related = $this->relatedAccount($condominium, $input['related_account_id'] ?? null);
        $invoiceType = $apportion === 'none' ? null : $this->invoiceType($condominium, $input['invoice_type_id'] ?? null);
        $reserve = $apportion === 'none' ? null : self::reservePercent($input['reserve_percent'] ?? null);
        $reserveAccount = (int)(filter_var($input['reserve_account_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if ($reserve !== null && $reserveAccount < 1) {
            throw new InvalidArgumentException('Escolha a conta de receita do fundo de reserva.');
        }
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        if (!RecurringEntries::isCompetence($competence) || \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn) === false) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM) e o vencimento (AAAA-MM-DD).');
        }
        $description = is_string($input['description'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['description'])) : '';
        $units = match ($apportion) {
            'all' => $this->billableUnits($condominium),
            'chosen' => EntryShortcuts::unitIds($input['unit_ids'] ?? null),
            default => [],
        };
        if ($apportion === 'all' && $units === []) {
            throw new InvalidArgumentException('O condomínio não tem unidades ativas que recebem rateio.');
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        $entries = [];
        $charges = [];
        try {
            for ($i = 0; $i < $repeat; $i++) {
                [$month, $due] = self::shift($competence, $dueOn, $i);
                $text = $label ? mb_substr($description, 0, 110) . ' (' . ($firstIndex + $i) . '/' . $totalIndex . ')' : $description;
                if ($apportion === 'none') {
                    $entries[] = (int)$this->registry->save($user, 'receivables', array_intersect_key($input, array_flip(['condominium_id', 'chart_account_id', 'cost_center_id', 'related_account_id', 'amount_cents', 'document_number', 'notes']))
                        + ['description' => $text, 'competence' => $month, 'due_on' => $due, 'status' => 'active'])['id'];
                    continue;
                }
                $base = ['condominium_id' => $condominium, 'kind' => 'extra', 'competence' => $month, 'due_on' => $due, 'unit_ids' => $units,
                    'document_number' => $input['document_number'] ?? null, 'cost_center_id' => $input['cost_center_id'] ?? null];
                $charge = $this->shortcuts->createCharge($user, $base + ['description' => $text, 'chart_account_id' => $input['chart_account_id'] ?? null,
                    'method' => $input['method'] ?? null, 'amount_cents' => $input['amount_cents'] ?? null, 'unit_amounts' => $input['unit_amounts'] ?? null]);
                $charges[] = (int)$charge['id'];
                if ($related !== null) {
                    $this->pdo->prepare('UPDATE erp_entries SET related_account_id = ? WHERE extra_charge_id = ?')->execute([$related, (int)$charge['id']]);
                }
                if ($reserve !== null) {
                    $shares = [];
                    foreach ($charge['entries'] as $entry) {
                        $shares[$entry['unit_id']] = number_format(max(1, (int)round($entry['amount_cents'] * $reserve / 100)) / 100, 2, ',', '');
                    }
                    $fund = $this->shortcuts->createCharge($user, $base + ['description' => mb_substr('Fundo de reserva — ' . $text, 0, 120), 'chart_account_id' => $reserveAccount,
                        'method' => 'custom', 'unit_amounts' => $shares]);
                    $charges[] = (int)$fund['id'];
                }
            }
            if ($apportion !== 'none' && $payer !== 'responsible') {
                $this->setPayer($charges, $payer);
            }
            if ($apportion !== 'none' && $invoiceType !== null) {
                $this->pdo->prepare('UPDATE erp_entries SET bundle_invoice_type_id = ? WHERE extra_charge_id IN (' . implode(',', $charges) . ')')->execute([$invoiceType]);
            }
            if ($apportion !== 'none') {
                $in = implode(',', $charges);
                $entries = array_map('intval', $this->pdo->query("SELECT id FROM erp_entries WHERE extra_charge_id IN ({$in}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($payer !== 'responsible' && $apportion !== 'none') {
            Audit::record('update', 'erp_extra_charges', $charges[0], null, ['_revenue_payer' => $payer, 'charges' => $charges]);
        }
        return ['entries' => $entries, 'charges' => $charges, 'months' => $repeat];
    }

    /** Conta relacionada: vazia = nenhuma; senão analítica, ativa, de receita ou despesa, do padrão ou deste condomínio. */
    private function relatedAccount(int $condominium, mixed $value): ?int
    {
        $id = self::optionalId($value);
        if ($id === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_chart_accounts WHERE id = ? AND kind = 'analitica' AND status = 'active' AND deleted_at IS NULL
            AND (condominium_id IS NULL OR condominium_id = ?)");
        $stmt->execute([$id, $condominium]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Escolha uma conta relacionada analítica e ativa do plano de contas.');
        }
        return $id;
    }

    /** Tipo da fatura (ADR 0331): vazio = boleto próprio; senão ativo, do padrão ou deste condomínio. */
    private function invoiceType(int $condominium, mixed $value): ?int
    {
        $id = self::optionalId($value, 'Escolha um tipo de fatura ativo do condomínio.');
        if ($id === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_invoice_types WHERE id = ? AND status = 'active' AND deleted_at IS NULL AND (condominium_id IS NULL OR condominium_id = ?)");
        $stmt->execute([$id, $condominium]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Escolha um tipo de fatura ativo do condomínio.');
        }
        return $id;
    }

    private static function optionalId(mixed $value, string $message = 'Escolha uma conta relacionada analítica e ativa do plano de contas.'): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException($message);
        }
        return $id;
    }

    /** Tipos de fatura ativos do condomínio e do padrão, mensais primeiro (o primeiro é o padrão da tela). @return list<array{id:int, label:string}> */
    public static function invoiceTypes(PDO $pdo, int $condominium): array
    {
        $stmt = $pdo->prepare("SELECT id, name FROM erp_invoice_types WHERE status = 'active' AND deleted_at IS NULL AND (condominium_id IS NULL OR condominium_id = ?)
            ORDER BY recurrence = 'mensal' DESC, condominium_id IS NULL, name, id");
        $stmt->execute([$condominium]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['name']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> unidades ativas do condomínio que recebem rateio (sem as isentas, ADR 0311) */
    private function billableUnits(int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND apportionment_exempt = 0 ORDER BY id LIMIT "
            . EntryShortcuts::MAX_UNITS);
        $stmt->execute([$condominium]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Pagador da conta = proprietário ou inquilino ativo da unidade (o mais antigo); sem ele, fica o padrão. @param list<int> $charges */
    private function setPayer(array $charges, string $role): void
    {
        $in = implode(',', array_map('intval', $charges));
        $this->pdo->prepare("UPDATE erp_entries e SET e.payer_resident_id = (SELECT r.id FROM erp_residents r WHERE r.unit_id = e.unit_id AND r.relationship = :role
                AND r.status = 'active' AND r.deleted_at IS NULL ORDER BY r.id LIMIT 1)
            WHERE e.extra_charge_id IN ({$in})")->execute(['role' => $role]);
    }
}
