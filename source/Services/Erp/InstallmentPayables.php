<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * Despesa parcelada (ADR 0195): uma compra ou um serviço pago em parcelas (obra em 6x, equipamento em 10x) vira N contas a
 * pagar de uma vez, em vez de N lançamentos digitados um a um.
 *
 * - Valor: o total, dividido em 2 a 60 parcelas iguais; os centavos que sobram vão para a primeira (regra pura split).
 * - Vencimentos: a primeira data e depois mês a mês, no mesmo dia; quando o mês não tem o dia, vale o último dia do mês
 *   (regra pura schedule: 31/01 → 28/02 → 31/03).
 * - Competência: a informada vale para todas (a despesa é do mês da compra); sem ela, cada parcela fica na competência do
 *   próprio vencimento.
 * - Descrição: "<descrição> (i/N)". Conta, centro de custo, fornecedor, documento, conta bancária sugerida e observações
 *   valem para todas.
 *
 * Cada parcela é gravada pelo RegistryService::save do cadastro "payables" (contas a pagar), com as mesmas regras do
 * formulário: escopo, conta analítica de despesa ativa, centro de custo, fornecedor, competência fechada (ADR 0031/0100)
 * e auditoria de cada lançamento. Tudo numa transação: se uma parcela é recusada, nenhuma fica (RegistryValidationException
 * com o erro e a parcela em _installment). dry_run monta as parcelas e confere a entrada sem gravar.
 */
final class InstallmentPayables
{
    public const MIN = 2;
    public const MAX = 60;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Valor de cada parcela (regra pura): partes iguais, a sobra dos centavos na primeira.
     *
     * @return list<int>
     */
    public static function split(int $totalCents, int $installments): array
    {
        $each = intdiv($totalCents, $installments);
        $parts = array_fill(0, $installments, $each);
        $parts[0] += $totalCents - $each * $installments;
        return $parts;
    }

    /**
     * Vencimentos (regra pura): mês a mês a partir do primeiro, no mesmo dia ou no último dia do mês.
     *
     * @return list<string>
     */
    public static function schedule(string $firstDueOn, int $installments): array
    {
        $first = new \DateTimeImmutable($firstDueOn);
        $day = (int)$first->format('j');
        $out = [];
        for ($i = 0; $i < $installments; $i++) {
            $month = $first->modify('first day of this month')->modify("+{$i} months");
            $out[] = $month->setDate((int)$month->format('Y'), (int)$month->format('n'), min($day, (int)$month->format('t')))->format('Y-m-d');
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function create(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $count = filter_var($input['installments'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN, 'max_range' => self::MAX]]);
        if ($count === false || $count === null) {
            throw new InvalidArgumentException(sprintf('Informe de %d a %d parcelas.', self::MIN, self::MAX));
        }
        $total = is_int($input['total'] ?? null) || is_string($input['total'] ?? null) ? Invoices::cents($input['total']) : null;
        if ($total === null || $total < $count) {
            throw new InvalidArgumentException('Informe o valor total (ex.: 1.234,56), de pelo menos 1 centavo por parcela.');
        }
        $first = $input['first_due_on'] ?? null;
        $date = is_string($first) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $first) : false;
        if ($date === false || $date->format('Y-m-d') !== $first) {
            throw new InvalidArgumentException('Informe o primeiro vencimento (AAAA-MM-DD).');
        }
        $competence = $input['competence'] ?? null;
        if ($competence !== null && $competence !== '' && (!is_string($competence) || !RecurringEntries::isCompetence($competence))) {
            throw new InvalidArgumentException('Competência: AAAA-MM, ou vazio para a do vencimento de cada parcela.');
        }
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        if ($description === '' || mb_strlen($description) > 150) {
            throw new InvalidArgumentException('Informe a descrição (até 150 caracteres; o sistema acrescenta " (i/N)").');
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de gravar

        $amounts = self::split($total, $count);
        $dues = self::schedule($first, $count);
        $common = [];
        foreach (['chart_account_id', 'cost_center_id', 'supplier_id', 'document_number', 'bank_account_id', 'notes'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $common[$field] = $input[$field];
            }
        }
        $plan = [];
        for ($i = 0; $i < $count; $i++) {
            $plan[] = ['installment' => $i + 1, 'description' => sprintf('%s (%d/%d)', $description, $i + 1, $count), 'amount_cents' => $amounts[$i],
                'due_on' => $dues[$i], 'competence' => $competence !== null && $competence !== '' ? $competence : substr($dues[$i], 0, 7)];
        }
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'installments' => $count, 'total_cents' => $total, 'plan' => $plan, 'entries' => []];
        if ($dryRun) {
            return $result;
        }
        $registry = new RegistryService($this->pdo, $this->scope);
        $this->pdo->beginTransaction();
        try {
            foreach ($plan as $p) {
                try {
                    $saved = $registry->save($user, 'payables', ['condominium_id' => (int)$condominium, 'description' => $p['description'],
                        'amount_cents' => number_format($p['amount_cents'] / 100, 2, ',', ''), 'due_on' => $p['due_on'], 'competence' => $p['competence']] + $common);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_installment' => sprintf('Parcela %d de %d.', $p['installment'], $count)]);
                }
                $result['entries'][] = ['id' => (int)$saved['id'], 'installment' => $p['installment'], 'description' => $p['description'],
                    'amount_cents' => $p['amount_cents'], 'due_on' => $p['due_on'], 'competence' => $p['competence']];
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $result;
    }
}
