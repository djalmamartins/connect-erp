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
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Reajuste em lote das tarifas de consumo do condomínio (ADR 0259). O cadastro de tarifas (AP-043) guarda as faixas
 * progressivas por tipo e vigência; a cobertura das tarifas (ADR 0258) mostra a vigência em vigor e se já há reajuste
 * cadastrado. Quando a concessionária reajusta a água ou o gás, a administradora recadastra faixa por faixa, com a nova
 * vigência e o preço recalculado à mão; uma faixa esquecida fica com o preço antigo e a leitura do mês sai errada.
 *
 * Reusa o que já existe:
 * - RecurringReadjustment::basisPoints e ::readjusted: o mesmo percentual (−50% a 100%, até 2 casas, diferente de zero) e
 *   o mesmo arredondamento dos reajustes de lançamentos fixos (ADR 0230) e de salários;
 * - RegistryService::save do cadastro "tariffs": cada faixa nova é criada pelo formulário, com o mesmo tipo, nome, início,
 *   fim e observação da faixa vigente, o preço reajustado e a nova vigência (valem as regras e a auditoria create). As
 *   faixas antigas não mudam: as leituras anteriores à nova vigência continuam usando o preço antigo (MeterReadings::bands).
 *
 * - Entram: a tarifa própria do condomínio vigente antes de valid_from, de cada tipo escolhido (padrão todos). Regra pura
 *   problem(): sem tarifa própria (o condomínio usa a padrão da Connect, que não é reajustada aqui) ou com vigência
 *   própria já cadastrada em valid_from ou depois. Sem tipos, esses ficam de fora e as contagens saem em skipped_default
 *   e skipped_already; com tipos, um recusado desfaz todos (RegistryValidationException com _form e _kind).
 * - valid_from: hoje ou uma data futura (um reajuste com data passada mudaria o preço de leituras já lançadas).
 * - Tudo ou nada, numa transação: uma faixa recusada pelo formulário desfaz todas. dry_run passa por tudo e desfaz.
 * - Auditoria: a create do formulário e um registro readjust por faixa nova (vigência e preço antes e depois, percentual
 *   e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem os tipos, as vigências, os limites
 * e os preços das faixas: nenhum nome de faixa ou observação. Motivo obrigatório. Nada é enviado.
 */
final class TariffReadjustment
{
    public const MAX_BANDS = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Motivo da recusa (regra pura); null quando o tipo pode ser reajustado.
     *
     * @param list<string> $ownDates vigências da tarifa própria do tipo
     */
    public static function problem(array $ownDates, string $validFrom): ?string
    {
        $dates = array_map(static fn (string $d): string => substr($d, 0, 10), $ownDates);
        return match (true) {
            array_filter($dates, static fn (string $d): bool => $d < $validFrom) === []
                => 'O condomínio não tem tarifa própria vigente antes da data para este tipo (usa a padrão da Connect): cadastre a tarifa antes de reajustar.',
            array_filter($dates, static fn (string $d): bool => $d >= $validFrom) !== [] => 'Já há vigência própria cadastrada nesta data ou depois para este tipo.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function readjust(User $user, array $input): array
    {
        $condominium = is_int($input['condominium_id'] ?? null) || is_string($input['condominium_id'] ?? null)
            ? filter_var($input['condominium_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $bp = RecurringReadjustment::basisPoints($input['percent'] ?? null);
        if ($bp === null) {
            throw new InvalidArgumentException('Percentual de −50 a 100, com até 2 casas, diferente de zero (ex.: 5,5).');
        }
        $validFrom = $input['valid_from'] ?? null;
        $date = is_string($validFrom) ? DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom) : false;
        if ($date === false || $date->format('Y-m-d') !== $validFrom) {
            throw new InvalidArgumentException('Vigência do reajuste: AAAA-MM-DD.');
        }
        if ($validFrom < date('Y-m-d')) {
            throw new InvalidArgumentException('O reajuste vale a partir de hoje ou de uma data futura, para não mudar o preço de leituras já lançadas.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do reajuste (5 a 255 caracteres).');
        }
        $options = array_keys(RegistryService::definition('tariffs')['fields']['kind']['options']);
        $chosen = null;
        if (array_key_exists('kinds', $input) && $input['kinds'] !== null) {
            if (!is_array($input['kinds']) || $input['kinds'] === []
                || array_diff(array_map(static fn (mixed $k): string => is_string($k) ? $k : '', $input['kinds']), $options) !== []) {
                throw new InvalidArgumentException('Tipos: ' . implode(', ', $options) . '.');
            }
            $chosen = array_values(array_intersect($options, $input['kinds']));
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $percent = number_format($bp / 100, 2, ',', '');
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'percent' => $percent, 'valid_from' => $validFrom, 'reason' => $reason,
            'skipped_default' => [], 'skipped_already' => [], 'kinds' => []];
        $created = [];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, kind, name, from_amount, to_amount, price_cents, valid_from, notes FROM erp_tariffs
                WHERE condominium_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY kind, valid_from, from_amount, id FOR UPDATE");
            $stmt->execute([(int)$condominium]);
            $own = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $own[(string)$r['kind']][] = $r;
            }
            foreach ($chosen ?? $options as $kind) {
                $rows = $own[$kind] ?? [];
                $dates = array_map(static fn (array $r): string => substr((string)$r['valid_from'], 0, 10), $rows);
                $problem = self::problem($dates, $validFrom);
                if ($problem !== null) {
                    if ($chosen !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_kind' => $kind]);
                    }
                    $result[array_filter($dates, static fn (string $d): bool => $d < $validFrom) === [] ? 'skipped_default' : 'skipped_already'][] = $kind;
                    continue;
                }
                $current = max(array_filter($dates, static fn (string $d): bool => $d < $validFrom));
                $bands = array_values(array_filter($rows, static fn (array $r): bool => substr((string)$r['valid_from'], 0, 10) === $current));
                if (count($created) + count($bands) > self::MAX_BANDS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d faixas: reajuste um tipo por vez.', self::MAX_BANDS));
                }
                $line = ['kind' => $kind, 'from_valid_from' => $current, 'bands' => []];
                foreach ($bands as $band) {
                    $before = (int)$band['price_cents'];
                    $after = RecurringReadjustment::readjusted($before, $bp);
                    try {
                        $saved = $registry->save($user, 'tariffs', ['condominium_id' => (string)$condominium, 'kind' => $kind, 'name' => (string)$band['name'],
                            'from_amount' => number_format((float)$band['from_amount'], 3, ',', ''),
                            'to_amount' => $band['to_amount'] === null ? null : number_format((float)$band['to_amount'], 3, ',', ''),
                            'price_cents' => number_format($after / 100, 2, ',', ''), 'valid_from' => $validFrom,
                            'notes' => $band['notes'] === null ? null : (string)$band['notes']]);
                    } catch (RegistryValidationException $error) {
                        throw new RegistryValidationException($error->errors + ['_kind' => $kind]);
                    }
                    $created[] = ['id' => (int)$saved['id'], 'kind' => $kind, 'from_valid_from' => $current, 'before' => $before, 'after' => $after];
                    $line['bands'][] = ['from_amount' => (float)$band['from_amount'], 'to_amount' => $band['to_amount'] === null ? null : (float)$band['to_amount'],
                        'price_before_cents' => $before, 'price_after_cents' => $after];
                }
                $result['kinds'][] = $line;
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($created as $c) {
                Audit::record('readjust', 'erp_tariffs', $c['id'], ['valid_from' => $c['from_valid_from'], 'price_cents' => $c['before']],
                    ['valid_from' => $validFrom, 'price_cents' => $c['after'], 'kind' => $c['kind'], 'percent' => $percent, 'reason' => $reason, 'batch' => count($created)]);
            }
        }
        $result['count'] = count($created);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
