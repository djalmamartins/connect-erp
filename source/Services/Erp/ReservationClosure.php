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
 * Cancelamento em lote das reservas de uma área comum interditada (ADR 0263). Quando a piscina entra em reforma, o salão
 * de festas é pintado ou a quadra fica fechada por um período, as reservas futuras da área (aguardando aprovação ou
 * confirmadas) precisam ser canceladas; hoje cada uma é cancelada à mão no formulário, e uma esquecida vira o morador
 * chegando com os convidados na área fechada. A baixa das pendentes vencidas (ADR 0221) só trata as que já passaram.
 *
 * Reusa o que já existe: cada reserva é regravada pelo RegistryService::save do cadastro "reservations" só com a situação
 * "Cancelada" (valem as regras e a auditoria update do formulário), como na baixa das pendentes (ADR 0221). O aviso da
 * outbox (ADR 0056) é só da aprovação, então cancelar não envia nada; o aviso aos moradores fica com o comunicado.
 *
 * - Entram: as reservas aguardando aprovação ou confirmadas da área no período from–to (de hoje em diante, até MAX_DAYS
 *   dias), ou só as reservation_ids informadas. A área precisa ser do condomínio, estar ativa e aceitar reservas (o
 *   formulário não regrava reserva de área inativa ou bloqueada: cancele antes de inativar ou bloquear a área, ADR 0229).
 * - Regra pura problem(): reserva não encontrada no condomínio ou de outra área, já cancelada, fora do período, com a taxa
 *   já cobrada (cobrança extra ativa da reserva, ADR 0212: cancele a cobrança antes), ou de unidade inativa (o formulário
 *   não regrava). Sem ids, as cobradas ficam de fora e a contagem sai em skipped_billed; as de unidade inativa, em
 *   skipped_inactive_unit.
 * - waitlist_to_review: inscrições aguardando na lista de espera da área nas datas do período (não são tocadas).
 * - Tudo ou nada, numa transação com as reservas travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _reservation). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro closure_cancel por reserva (situação anterior, data, área, período e
 *   motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, data, situação anterior e taxa:
 * nenhum morador, unidade ou observação. Até MAX_RESERVATIONS por vez; motivo obrigatório.
 */
final class ReservationClosure
{
    public const MAX_RESERVATIONS = 500;
    public const MAX_DAYS = 366;
    public const OPEN = ['active', 'confirmed'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a reserva pode ser cancelada. */
    public static function problem(?array $row, int $areaId, string $from, string $to, bool $billed = false, bool $unitActive = true): ?string
    {
        $on = substr((string)($row['reserved_on'] ?? ''), 0, 10);
        return match (true) {
            $row === null || (int)($row['common_area_id'] ?? 0) !== $areaId => 'Reserva não encontrada nesta área do condomínio.',
            !in_array($row['status'] ?? '', self::OPEN, true) => 'A reserva já está cancelada.',
            $on < $from || $on > $to => 'A reserva está fora do período da interdição.',
            $billed => 'A taxa da reserva já foi cobrada: cancele a cobrança extra antes.',
            !$unitActive => 'A unidade da reserva está inativa: corrija no cadastro.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $area = $int($input['common_area_id'] ?? null);
        if ($area === false) {
            throw new InvalidArgumentException('Escolha a área comum interditada.');
        }
        $date = static function (mixed $value, string $label): string {
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException($label . ': AAAA-MM-DD.');
            }
            return $value;
        };
        $from = $date($input['from'] ?? null, 'Início da interdição');
        $to = $date($input['to'] ?? null, 'Fim da interdição');
        $today = date('Y-m-d');
        if ($from < $today) {
            throw new InvalidArgumentException('A interdição começa hoje ou numa data futura (as reservas que já passaram seguem pela baixa das pendentes).');
        }
        if ($to < $from || (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período: o fim depois do início, até %d dias.', self::MAX_DAYS));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da interdição (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('reservation_ids', $input) && $input['reservation_ids'] !== null) {
            if (!is_array($input['reservation_ids']) || $input['reservation_ids'] === []) {
                throw new InvalidArgumentException('Reservas: informe a lista de ids ou deixe vazio para todas as do período.');
            }
            $ids = [];
            foreach ($input['reservation_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Reservas: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_RESERVATIONS) {
                throw new InvalidArgumentException(sprintf('Até %d reservas por vez.', self::MAX_RESERVATIONS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT status, booking_mode FROM erp_common_areas WHERE id = ? AND condominium_id = ? AND deleted_at IS NULL');
        $stmt->execute([(int)$area, (int)$condominium]);
        $areaRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($areaRow)) {
            throw new InvalidArgumentException('Área comum não encontrada neste condomínio.');
        }
        if ($areaRow['status'] !== 'active') {
            throw new InvalidArgumentException('A área está inativa: o formulário não regrava as reservas. Cancele as reservas antes de inativar a área.');
        }
        if (in_array($areaRow['booking_mode'], ['blocked', 'none'], true)) {
            throw new InvalidArgumentException('A área está bloqueada para reservas: o formulário não regrava as reservas. Cancele as reservas antes de bloquear a área.');
        }

        $fields = array_keys(RegistryService::definition('reservations')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'common_area_id' => (int)$area, 'dry_run' => $dryRun, 'from' => $from, 'to' => $to, 'reason' => $reason,
            'skipped_billed' => 0, 'skipped_inactive_unit' => 0, 'waitlist_to_review' => 0, 'reservations' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_reservations WHERE condominium_id = ? AND common_area_id = ? AND deleted_at IS NULL
                    AND status IN ('active', 'confirmed') AND reserved_on BETWEEN ? AND ? ORDER BY reserved_on, id LIMIT " . (self::MAX_RESERVATIONS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, (int)$area, $from, $to]);
                $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($found) > self::MAX_RESERVATIONS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d reservas no período: cancele em partes (período menor).', self::MAX_RESERVATIONS));
                }
                [$billed, $units] = [$this->billed($found), $this->activeUnits((int)$condominium, $found)];
                $rows = [];
                foreach ($found as $r) {
                    if (isset($billed[(int)$r['id']])) {
                        $result['skipped_billed']++;
                    } elseif (!isset($units[(int)$r['id']])) {
                        $result['skipped_inactive_unit']++;
                    } else {
                        $rows[] = $r;
                    }
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                [$billed, $units] = [$this->billed(array_values($byId)), $this->activeUnits((int)$condominium, array_values($byId))];
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, (int)$area, $from, $to, isset($billed[$id]), isset($units[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_reservation' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'fee_cents' => number_format(((int)$value) / 100, 2, ',', ''),
                        in_array($field, ['starts_time', 'ends_time'], true) => substr((string)$value, 0, 5),
                        $field === 'reserved_on' => substr((string)$value, 0, 10),
                        default => $value,
                    };
                }
                try {
                    $registry->save($user, 'reservations', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_reservation' => (string)$row['id']]);
                }
                $result['reservations'][] = ['id' => (int)$row['id'], 'reserved_on' => substr((string)$row['reserved_on'], 0, 10), 'from_status' => (string)$row['status'],
                    'fee_cents' => $row['fee_cents'] !== null ? (int)$row['fee_cents'] : null];
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_waitlist WHERE condominium_id = ? AND common_area_id = ? AND deleted_at IS NULL AND status = 'active'
                AND desired_on BETWEEN ? AND ?");
            $stmt->execute([(int)$condominium, (int)$area, $from, $to]);
            $result['waitlist_to_review'] = (int)$stmt->fetchColumn();
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['reservations'] as $r) {
                Audit::record('closure_cancel', 'erp_reservations', $r['id'], ['status' => $r['from_status']],
                    ['status' => 'inactive', 'reserved_on' => $r['reserved_on'], 'common_area_id' => (int)$area, 'closure_from' => $from, 'closure_to' => $to,
                        'reason' => $reason, 'batch' => count($result['reservations'])]);
            }
        }
        $result['count'] = count($result['reservations']);
        $result['confirmed'] = count(array_filter($result['reservations'], static fn (array $r): bool => $r['from_status'] === 'confirmed'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }

    /**
     * Ids das reservas com cobrança extra ativa da taxa (ADR 0212).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function billed(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int)$r['id'], $rows);
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT reservation_id FROM erp_extra_charges WHERE kind = 'extra' AND status = 'active' AND reservation_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Ids das reservas cuja unidade segue ativa no condomínio: o formulário só regrava essas.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeUnits(int $condominium, array $rows): array
    {
        $units = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['unit_id'] ?? 0), $rows))));
        $ok = [];
        if ($units !== []) {
            $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ("
                . implode(',', array_fill(0, count($units), '?')) . ')');
            $stmt->execute(array_merge([$condominium], $units));
            $ok = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $result = [];
        foreach ($rows as $r) {
            if (isset($ok[(int)($r['unit_id'] ?? 0)])) {
                $result[(int)$r['id']] = true;
            }
        }
        return $result;
    }
}
