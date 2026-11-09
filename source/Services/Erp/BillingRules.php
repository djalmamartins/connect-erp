<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Regras de cobrança por condomínio e índices de correção (ADR 0040).
 *
 * - rule(): a regra do condomínio; sem regra própria, o padrão da Connect (condominium_id NULL); sem
 *   linha nenhuma, LateCharges::DEFAULT_RULE.
 * - save(): grava a regra do condomínio (escopo) ou o padrão (só com acesso a todos os condomínios).
 * - Índices: lançados um a um (setRate) ou importados de texto "AAAA-MM;0,44" (import), sem rede.
 *   A importação valida o arquivo inteiro antes de gravar. Tudo auditado.
 */
final class BillingRules
{
    public const INDEXES = ['IPCA', 'NONE'];
    /** Teto de meses num arquivo de índices: 50 anos (ADR 0110). */
    public const MAX_INDEX_MONTHS = 600;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Valida e normaliza a regra (regra pura). @return array{fine_bp:int, monthly_interest_bp:int, price_index:string, breach_days_late:int, breach_open_installments:int} */
    public static function normalize(array $input): array
    {
        $rule = [];
        foreach (['fine_bp' => [0, 1000], 'monthly_interest_bp' => [0, 1000], 'breach_days_late' => [1, 365], 'breach_open_installments' => [0, 60]] as $field => [$min, $max]) {
            $value = filter_var($input[$field] ?? LateCharges::DEFAULT_RULE[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($value === false) {
                throw new InvalidArgumentException("{$field}: use um inteiro entre {$min} e {$max}.");
            }
            $rule[$field] = (int)$value;
        }
        $index = strtoupper((string)($input['price_index'] ?? LateCharges::DEFAULT_RULE['price_index']));
        if (!in_array($index, self::INDEXES, true)) {
            throw new InvalidArgumentException('Índice de correção: IPCA ou NONE.');
        }
        $rule['price_index'] = $index;
        return $rule;
    }

    /**
     * Linhas "AAAA-MM;variação" (vírgula ou ponto decimal), uma por mês (regra pura). Mês futuro não
     * tem índice publicado e é recusado; no máximo MAX_INDEX_MONTHS meses por arquivo (ADR 0110).
     *
     * @return array<string, float> competência => variação em %
     */
    public static function parseIndexFile(string $content, ?string $currentCompetence = null): array
    {
        $currentCompetence ??= date('Y-m');
        $rates = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($content)) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $n = $index + 1;
            if (!preg_match('/^(\d{4}-(?:0[1-9]|1[0-2]))\s*[;,\t]\s*(-?\d{1,3}(?:[.,]\d{1,6})?)$/', $line, $m)) {
                throw new InvalidArgumentException("Linha {$n}: use AAAA-MM;variação (ex.: 2026-10;0,44).");
            }
            if ($m[1] > $currentCompetence) {
                throw new InvalidArgumentException("Linha {$n}: competência {$m[1]} ainda não tem índice publicado.");
            }
            if (isset($rates[$m[1]])) {
                throw new InvalidArgumentException("Linha {$n}: competência {$m[1]} repetida.");
            }
            $rate = (float)str_replace(',', '.', $m[2]);
            if ($rate <= -100 || $rate > 100) {
                throw new InvalidArgumentException("Linha {$n}: variação fora do intervalo.");
            }
            $rates[$m[1]] = $rate;
            if (count($rates) > self::MAX_INDEX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Importe no máximo %d meses por arquivo.', self::MAX_INDEX_MONTHS));
            }
        }
        if ($rates === []) {
            throw new InvalidArgumentException('Arquivo de índices vazio.');
        }
        return $rates;
    }

    /** Regra que vale para o condomínio (com 'source': condominium, default ou builtin). */
    public function rule(?int $condominiumId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_billing_rules WHERE condominium_id <=> :c OR condominium_id IS NULL ORDER BY condominium_id IS NULL LIMIT 1');
        $stmt->execute(['c' => $condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return LateCharges::DEFAULT_RULE + ['condominium_id' => $condominiumId, 'source' => 'builtin'];
        }
        return self::normalize($row) + ['condominium_id' => $condominiumId,
            'source' => $row['condominium_id'] === null ? 'default' : 'condominium'];
    }

    /** Regra visível ao usuário; condomínio fora do escopo = 404. */
    public function ruleFor(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null && !$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
        return $this->rule($condominiumId);
    }

    public function save(User $user, ?int $condominiumId, array $input): array
    {
        if ($condominiumId === null && !$this->scope->hasGlobalAccess($user)) {
            throw new RegistryForbidden();
        }
        if ($condominiumId !== null && !$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
        $before = $this->rule($condominiumId);
        $rule = self::normalize($input);
        $this->pdo->prepare('INSERT INTO erp_billing_rules (condominium_id, fine_bp, monthly_interest_bp, price_index, breach_days_late, breach_open_installments, updated_by)
            VALUES (:c, :f, :i, :x, :d, :o, :u)
            ON DUPLICATE KEY UPDATE fine_bp = VALUES(fine_bp), monthly_interest_bp = VALUES(monthly_interest_bp), price_index = VALUES(price_index),
                breach_days_late = VALUES(breach_days_late), breach_open_installments = VALUES(breach_open_installments), updated_by = VALUES(updated_by)')
            ->execute(['c' => $condominiumId, 'f' => $rule['fine_bp'], 'i' => $rule['monthly_interest_bp'], 'x' => $rule['price_index'],
                'd' => $rule['breach_days_late'], 'o' => $rule['breach_open_installments'], 'u' => (int)$user->id]);
        $id = (int)$this->pdo->query('SELECT id FROM erp_billing_rules WHERE scope_key = ' . (int)($condominiumId ?? 0))->fetchColumn();
        Audit::record('update', 'erp_billing_rules', $id, array_intersect_key($before, $rule), $rule);
        return $this->rule($condominiumId);
    }

    /** @return array<string, float> competência => variação em %, de $from a $to */
    public function rates(string $code, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT competence, rate_percent FROM erp_price_indexes WHERE code = :c AND competence BETWEEN :f AND :t ORDER BY competence');
        $stmt->execute(['c' => $code, 'f' => $from, 't' => $to]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<array{competence:string, rate_percent:float}> */
    public function listRates(string $code): array
    {
        $this->assertIndex($code);
        $stmt = $this->pdo->prepare('SELECT competence, rate_percent FROM erp_price_indexes WHERE code = :c ORDER BY competence DESC LIMIT 240');
        $stmt->execute(['c' => $code]);
        return array_map(static fn (array $r): array => ['competence' => (string)$r['competence'], 'rate_percent' => (float)$r['rate_percent']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Grava os índices (um mês ou um arquivo inteiro), substituindo o valor de um mês já lançado.
     *
     * @param array<string, float> $rates
     * @return array{code:string, saved:int}
     */
    public function saveRates(User $user, string $code, array $rates): array
    {
        $this->assertIndex($code);
        if (!$this->scope->hasGlobalAccess($user)) {
            throw new RegistryForbidden();   // o índice vale para todos os condomínios
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO erp_price_indexes (code, competence, rate_percent, updated_by) VALUES (:c, :m, :r, :u)
                ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), updated_by = VALUES(updated_by)');
            foreach ($rates as $competence => $rate) {
                $stmt->execute(['c' => $code, 'm' => $competence, 'r' => number_format($rate, 6, '.', ''), 'u' => (int)$user->id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        Audit::record('update', 'erp_price_indexes', null, null, ['code' => $code, 'months' => array_keys($rates)]);
        return ['code' => $code, 'saved' => count($rates)];
    }

    private function assertIndex(string $code): void
    {
        if ($code !== 'IPCA') {
            throw new InvalidArgumentException('Índice disponível: IPCA.');
        }
    }
}
