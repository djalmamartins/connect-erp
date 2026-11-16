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
 * PENDENTE: tipo da fatura (o modelo agrupa a conta numa fatura de um tipo; aqui as contas avulsas não entram em
 * fatura) e anexos no mesmo envio (cada conta gerada aceita anexo na ficha do lançamento, ADR 0174).
 */
final class RevenueLaunch
{
    public const APPORTION = ['none' => 'Sem rateio (receita do condomínio)', 'all' => 'Cobrar de todas as unidades', 'chosen' => 'Cobrar das unidades escolhidas'];
    public const PAYERS = ['responsible' => 'Responsável pela cobrança da unidade', 'owner' => 'Proprietário', 'tenant' => 'Inquilino'];
    public const MAX_REPEAT = 24;

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
        $label = !empty($input['repeat_label']) && $repeat > 1;
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
                $text = $label ? mb_substr($description, 0, 110) . ' (' . ($i + 1) . '/' . $repeat . ')' : $description;
                if ($apportion === 'none') {
                    $entries[] = (int)$this->registry->save($user, 'receivables', array_intersect_key($input, array_flip(['condominium_id', 'chart_account_id', 'cost_center_id', 'amount_cents', 'document_number', 'notes']))
                        + ['description' => $text, 'competence' => $month, 'due_on' => $due, 'status' => 'active'])['id'];
                    continue;
                }
                $base = ['condominium_id' => $condominium, 'kind' => 'extra', 'competence' => $month, 'due_on' => $due, 'unit_ids' => $units,
                    'document_number' => $input['document_number'] ?? null, 'cost_center_id' => $input['cost_center_id'] ?? null];
                $charge = $this->shortcuts->createCharge($user, $base + ['description' => $text, 'chart_account_id' => $input['chart_account_id'] ?? null,
                    'method' => $input['method'] ?? null, 'amount_cents' => $input['amount_cents'] ?? null, 'unit_amounts' => $input['unit_amounts'] ?? null]);
                $charges[] = (int)$charge['id'];
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
