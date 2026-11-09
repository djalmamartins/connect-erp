<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * Gera os lançamentos de uma competência a partir dos lançamentos fixos ativos (AP-021, ADR 0027).
 *
 * - Só modelos do escopo do usuário e vigentes na competência (início ≤ competência ≤ fim).
 * - Cada lançamento passa pelo RegistryService ('entries'): mesmas validações, escopo e auditoria
 *   de um lançamento digitado. Nasce em aberto, com vencimento no dia do modelo.
 * - Sem duplicar: o modelo é travado (SELECT … FOR UPDATE), o vínculo já existente é pulado e a
 *   chave única (recurring_entry_id, competence) barra uma geração concorrente.
 * - Um modelo com problema (conta inativa, por exemplo) não impede os outros: vai para 'failed'.
 */
final class RecurringEntries
{
    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = $registry ?? new RegistryService($this->pdo, $this->scope);
    }

    /** Competência AAAA-MM válida (mês 01 a 12). */
    public static function isCompetence(string $competence): bool
    {
        return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence);
    }

    /** Vencimento do mês: dia do modelo (1 a 28, existe em todo mês) dentro da competência. */
    public static function dueDate(string $competence, int $day): string
    {
        if (!self::isCompetence($competence) || $day < 1 || $day > 28) {
            throw new InvalidArgumentException('Competência ou dia inválido.');
        }
        return sprintf('%s-%02d', $competence, $day);
    }

    /** Modelo vale para a competência? (comparação de texto AAAA-MM) */
    public static function appliesTo(string $competence, string $starts, ?string $ends): bool
    {
        return $starts <= $competence && ($ends === null || $ends === '' || $competence <= $ends);
    }

    /**
     * @return array{competence: string, created: list<int>, skipped: list<int>, failed: array<int, array<string, string>>}
     *   created = ids dos lançamentos novos; skipped e failed = ids dos modelos.
     */
    public function generate(User $user, string $competence, ?int $condominiumId = null): array
    {
        if (!self::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $result = ['competence' => $competence, 'created' => [], 'skipped' => [], 'failed' => []];
        $ids = $this->scope->allowedIds($user);
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return $result;
        }
        $in = implode(',', array_map('intval', $ids));
        $templates = $this->pdo->query("SELECT * FROM erp_recurring_entries
            WHERE deleted_at IS NULL AND status = 'active' AND condominium_id IN ({$in}) ORDER BY condominium_id, id")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($templates as $template) {
            $templateId = (int)$template['id'];
            if (!self::appliesTo($competence, (string)$template['starts_competence'], $template['ends_competence'])) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('SELECT id FROM erp_recurring_entries WHERE id = :id FOR UPDATE')->execute(['id' => $templateId]);
                $exists = $this->pdo->prepare('SELECT 1 FROM erp_entries WHERE recurring_entry_id = :template AND competence = :competence LIMIT 1');
                $exists->execute(['template' => $templateId, 'competence' => $competence]);
                if ($exists->fetchColumn()) {
                    $this->pdo->commit();
                    $result['skipped'][] = $templateId;
                    continue;
                }
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $template['condominium_id'], 'nature' => $template['nature'],
                    'chart_account_id' => $template['chart_account_id'], 'cost_center_id' => $template['cost_center_id'],
                    'bank_account_id' => $template['bank_account_id'], 'supplier_id' => $template['supplier_id'],
                    'description' => $template['description'], 'amount_cents' => number_format(((int)$template['amount_cents']) / 100, 2, ',', ''),
                    'competence' => $competence, 'due_on' => self::dueDate($competence, (int)$template['day_of_month']),
                    'notes' => $template['notes'],
                ]);
                $this->pdo->prepare('UPDATE erp_entries SET recurring_entry_id = :template WHERE id = :id')
                    ->execute(['template' => $templateId, 'id' => $entry['id']]);
                $this->pdo->commit();
                $result['created'][] = (int)$entry['id'];
            } catch (RegistryValidationException $exception) {
                $this->pdo->rollBack();
                $result['failed'][$templateId] = $exception->errors;
            } catch (PDOException $exception) {
                $this->pdo->rollBack();
                if (($exception->errorInfo[1] ?? null) === 1062) {
                    $result['skipped'][] = $templateId;   // gerado em paralelo por outra requisição
                    continue;
                }
                throw $exception;
            }
        }
        return $result;
    }
}
