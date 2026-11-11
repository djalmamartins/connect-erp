<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Situação das autorizações de visitantes do condomínio numa data (ADR 0234), somente leitura. O cadastro de visitantes
 * (AP-012) guarda os visitantes frequentes e prestadores autorizados por unidade, com acesso permanente ou "autorizado
 * até" uma data; a lista mostra visitante por visitante. Ninguém vê, de uma vez, quantas autorizações seguem "Liberado"
 * com a data já vencida (a portaria pode deixar entrar quem não está mais autorizado), quantas vencem logo e quantas
 * ficaram sem data nem acesso permanente (cadastro antigo, de antes da regra do formulário).
 *
 * Reusa o que já existe: as situações do cadastro (RegistryService::definition('visitors'): Liberado e Bloqueado) e a
 * regra do formulário (sem acesso permanente, a data "autorizado até" é obrigatória). Sem os excluídos.
 *
 * Regra pura classify() e summarize(), na data de referência on (padrão hoje):
 * - permanent: liberado com acesso permanente;
 * - valid: liberado com a data depois da janela (on + within_days);
 * - expiring: liberado com a data de on até on + within_days;
 * - expired_active: liberado com a data antes de on (pede atenção);
 * - no_date: liberado sem acesso permanente e sem data (pede atenção);
 * - blocked: bloqueado no cadastro.
 * Uma linha por situação, sempre as seis; totals.visitors é a soma das linhas, totals.active a soma das cinco liberadas e
 * totals.attention = expired_active + no_date. expired_by_age separa os vencidos e liberados pelos dias desde o
 * vencimento (até 30, 31 a 90, mais de 90), somando expired_active.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações e as contagens: nenhum nome, documento, telefone, unidade ou observação.
 */
final class VisitorAuthorizations
{
    public const MAX_ROWS = 50000;
    public const SITUATIONS = [
        'permanent' => 'Acesso permanente',
        'valid' => 'Autorizado, fora da janela',
        'expiring' => 'Vence na janela',
        'expired_active' => 'Vencido e ainda liberado',
        'no_date' => 'Liberado sem data nem acesso permanente',
        'blocked' => 'Bloqueado',
    ];
    public const AGES = ['ate_30' => 30, 'de_31_a_90' => 90, 'mais_de_90' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação de uma autorização na data $on, com a janela que termina em $until (regra pura). */
    public static function classify(array $row, string $on, string $until): string
    {
        if (($row['status'] ?? '') !== 'active') {
            return 'blocked';
        }
        if (!empty($row['permanent_access'])) {
            return 'permanent';
        }
        $date = substr((string)($row['valid_until'] ?? ''), 0, 10);
        return match (true) {
            $date === '' => 'no_date',
            $date < $on => 'expired_active',
            $date <= $until => 'expiring',
            default => 'valid',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{status:string, permanent_access:bool, valid_until:?string}> $visitors
     * @return array{rows:list<array>, totals:array<string, int>, expired_by_age:array<string, int>}
     */
    public static function summarize(array $visitors, string $on, int $withinDays): array
    {
        $until = (new DateTimeImmutable($on))->modify(sprintf('+%d days', $withinDays))->format('Y-m-d');
        $status = RegistryService::definition('visitors')['status'];
        $counts = array_fill_keys(array_keys(self::SITUATIONS), 0);
        $ages = array_fill_keys(array_keys(self::AGES), 0);
        foreach ($visitors as $visitor) {
            $situation = self::classify($visitor, $on, $until);
            $counts[$situation]++;
            if ($situation === 'expired_active') {
                $days = (int)(new DateTimeImmutable(substr((string)$visitor['valid_until'], 0, 10)))->diff(new DateTimeImmutable($on))->days;
                $ages[$days <= 30 ? 'ate_30' : ($days <= 90 ? 'de_31_a_90' : 'mais_de_90')]++;
            }
        }
        $rows = [];
        foreach (self::SITUATIONS as $situation => $label) {
            $rows[] = ['situation' => $situation, 'situation_label' => $label, 'status_label' => $status[$situation === 'blocked' ? 'inactive' : 'active'],
                'visitors' => $counts[$situation]];
        }
        return ['window_until' => $until, 'rows' => $rows, 'totals' => ['visitors' => array_sum($counts), 'active' => array_sum($counts) - $counts['blocked'],
            'attention' => $counts['expired_active'] + $counts['no_date']], 'expired_by_age' => $ages];
    }

    public function build(User $user, mixed $condominiumId, mixed $on = null, mixed $withinDays = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $on === null || $on === '' ? date('Y-m-d') : $on;
        $date = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $within = $withinDays === null || $withinDays === '' ? 30
            : (is_int($withinDays) || is_string($withinDays) ? filter_var($withinDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]) : false);
        if ($within === false) {
            throw new InvalidArgumentException('Janela de vencimento: de 1 a 365 dias.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT status, permanent_access, valid_until FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($visitors) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d visitantes cadastrados: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'on' => $on, 'within_days' => (int)$within] + self::summarize(array_map(static fn (array $v): array => [
            'status' => (string)$v['status'], 'permanent_access' => (bool)$v['permanent_access'],
            'valid_until' => $v['valid_until'] !== null ? substr((string)$v['valid_until'], 0, 10) : null], $visitors), $on, (int)$within);
    }
}
