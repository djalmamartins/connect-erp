<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Fechamento do mês em lote (ADR 0205): fecha a mesma competência de vários condomínios de uma vez, a rotina do começo
 * de mês da administradora, passando cada um pela conferência antes de fechar (ClosingReadiness, ADR 0181).
 *
 * - Recusa (regra pura reasons): competência já fechada; conferência com erro (Balanço que não fecha, pago sem conta
 *   bancária), que nunca fecha em lote; conferência com aviso (mês anterior aberto, contas em aberto, extrato sem
 *   conciliação, saldo negativo), a não ser com allow_warnings = true.
 * - Primeiro confere todos e junta as recusas: com alguma, nada é fechado e MonthClosingBatchRefused traz a lista
 *   completa (condomínio e motivos), para corrigir de uma vez.
 * - Depois fecha todos numa transação só, cada um pelo MonthClosing::close (a mesma foto do resumo, a trava da
 *   competência e a auditoria do fechamento de um por um): se um falhar (fechado por outra pessoa no meio), desfaz todos.
 *   dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a do MonthClosing::close e um registro close_batch por condomínio (competência, tamanho do lote,
 *   allow_warnings e os avisos aceitos), só quando grava.
 *
 * Escopo (ADR-002): todos os condomínios precisam estar no escopo (um fora = CondominiumAccessDenied, 404, antes de
 * consultar). Até MAX_CONDOMINIUMS por vez. Saem só nomes de condomínio, códigos e totais.
 */
final class MonthClosingBatch
{
    public const MAX_CONDOMINIUMS = 100;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Motivos da recusa a partir da conferência (regra pura); lista vazia quando pode fechar.
     *
     * @param array{checks: list<array{code:string, level:string, message:string}>} $readiness
     * @return list<array{code:string, level:string, message:string}>
     */
    public static function reasons(array $readiness, bool $allowWarnings): array
    {
        $out = [];
        foreach ($readiness['checks'] as $check) {
            $blocking = $check['code'] === 'already_closed' || $check['level'] === 'error' || ($check['level'] === 'warning' && !$allowWarnings);
            if ($blocking) {
                $out[] = ['code' => $check['code'], 'level' => $check['level'], 'message' => $check['message']];
            }
        }
        return $out;
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os condomínios.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Condomínios: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_CONDOMINIUMS) {
            throw new InvalidArgumentException(sprintf('Feche até %d condomínios por vez.', self::MAX_CONDOMINIUMS));
        }
        return array_values($ids);
    }

    /** @param array<string, mixed> $input */
    public function close(User $user, array $input): array
    {
        $competence = $input['competence'] ?? null;
        if (!is_string($competence) || !RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM).');
        }
        $ids = self::ids($input['condominium_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $allowWarnings = filter_var($input['allow_warnings'] ?? false, FILTER_VALIDATE_BOOLEAN);
        foreach ($ids as $id) {
            $this->scope->assertAccess($user, $id);   // 404 antes de consultar
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name FROM condominiums WHERE id IN ({$marks})");
        $stmt->execute($ids);
        $names = array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

        $readiness = new ClosingReadiness($this->pdo, $this->scope);
        $refused = [];
        $accepted = [];
        foreach ($ids as $id) {
            $check = $readiness->check($user, $id, $competence);
            $reasons = self::reasons($check, $allowWarnings);
            if ($reasons !== []) {
                $refused[] = ['condominium_id' => $id, 'name' => $names[$id] ?? '', 'reasons' => $reasons];
                continue;
            }
            $accepted[$id] = array_values(array_map(static fn (array $c): string => $c['code'],
                array_filter($check['checks'], static fn (array $c): bool => $c['level'] === 'warning')));
        }
        if ($refused !== []) {
            throw new MonthClosingBatchRefused($refused);
        }

        $result = ['competence' => $competence, 'dry_run' => $dryRun, 'allow_warnings' => $allowWarnings, 'count' => count($ids),
            'revenue_cents' => 0, 'expense_cents' => 0, 'condominiums' => []];
        $closing = new MonthClosing($this->pdo, $this->scope);
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                try {
                    $closed = $closing->close($user, $id, $competence);
                } catch (DomainException $error) {   // fechado por outra pessoa entre a conferência e o fechamento
                    throw new MonthClosingBatchRefused([['condominium_id' => $id, 'name' => $names[$id] ?? '',
                        'reasons' => [['code' => 'already_closed', 'level' => 'info', 'message' => $error->getMessage()]]]]);
                }
                $result['revenue_cents'] += $closed['revenue_cents'];
                $result['expense_cents'] += $closed['expense_cents'];
                $result['condominiums'][] = ['condominium_id' => $id, 'name' => $names[$id] ?? '', 'closing_id' => $closed['id'],
                    'revenue_cents' => $closed['revenue_cents'], 'expense_cents' => $closed['expense_cents'],
                    'open_revenue_cents' => $closed['open_revenue_cents'], 'open_expense_cents' => $closed['open_expense_cents'],
                    'accepted_warnings' => $accepted[$id]];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['condominiums'] as $c) {
                Audit::record('close_batch', 'erp_month_closings', $c['closing_id'], null, ['competence' => $competence, 'batch' => count($ids),
                    'allow_warnings' => $allowWarnings, 'accepted_warnings' => $c['accepted_warnings']]);
            }
        }
        $result['changed'] = $dryRun ? 0 : count($result['condominiums']);
        return $result;
    }
}
