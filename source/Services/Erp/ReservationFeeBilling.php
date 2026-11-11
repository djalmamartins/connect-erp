<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Cobrança das taxas de reserva em lote (ADR 0212): a reserva confirmada do salão de festas ou da churrasqueira tem taxa
 * (fee_cents, a da área quando o formulário não informa), mas nada a cobrava; a administradora lança uma cobrança extra
 * por reserva, à mão, e às vezes esquece ou cobra duas vezes.
 *
 * Reusa o que já existe, sem lançamento paralelo:
 * - CollectionReports::period: o período das reservas (datas válidas, até MAX_DAYS dias);
 * - EntryShortcuts::createCharge (AP-028, ADR 0057): cada taxa vira uma cobrança extra de uma unidade, valor fixo, com a
 *   conta de receita, a competência do mês da reserva, o vencimento e o vínculo reservation_id; as mesmas regras (conta
 *   de receita válida, mês fechado, vencimento não passado), a trava de origem das contas e a auditoria da cobrança.
 *   O createCharge passou a aceitar reservation_id também na cobrança extra e a gravar na transação de quem chamou.
 *
 * - Entram: reservas confirmadas do condomínio no período, não excluídas, com taxa maior que zero e sem cobrança extra
 *   ativa da própria reserva (cancelada a cobrança, a reserva volta a entrar). As já cobradas vêm em already_billed.
 * - Tudo ou nada, numa transação com as reservas travadas (FOR UPDATE): uma recusada pelo createCharge (mês fechado,
 *   unidade inativa) desfaz todas (RegistryValidationException com _form e _reservation). dry_run passa por tudo e desfaz
 *   no fim. Auditoria: a do createCharge e um registro bill_fee por reserva (cobrança gerada), só quando grava.
 * - Nada é enviado: a cobrança extra não manda aviso (o boleto, quando houver, segue o fluxo da cobrança).
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = 404). Saem reserva, área, data, taxa e a cobrança: nenhum nome de
 * morador. Até MAX_RESERVATIONS por vez.
 */
final class ReservationFeeBilling
{
    public const MAX_RESERVATIONS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Descrição da cobrança (regra pura), até 120 caracteres: "Taxa de reserva: Salão de festas em 03/10/2026". */
    public static function description(string $area, string $reservedOn): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($reservedOn, 0, 10));
        $suffix = ' em ' . ($date === false ? $reservedOn : $date->format('d/m/Y'));
        return mb_substr('Taxa de reserva: ' . mb_substr(trim($area), 0, 120 - 17 - mb_strlen($suffix)), 0, 120 - mb_strlen($suffix)) . $suffix;
    }

    /** @param array<string, mixed> $input */
    public function bill(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$from, $to] = CollectionReports::period(is_string($input['from'] ?? null) ? $input['from'] : '', is_string($input['to'] ?? null) ? $input['to'] : '');
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($due === false || $due->format('Y-m-d') !== $dueOn || $dueOn < date('Y-m-d')) {
            throw new InvalidArgumentException('Informe o vencimento das cobranças (AAAA-MM-DD, a partir de hoje).');   // a mesma regra do createCharge
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$this->scope->canAccess($user, (int)$condominium)) {
            throw new RegistryNotFound();   // 404 antes de consultar
        }
        $c = (int)$condominium;

        $result = ['condominium_id' => $c, 'from' => $from, 'to' => $to, 'dry_run' => $dryRun, 'count' => 0, 'total_cents' => 0, 'already_billed' => 0, 'reservations' => []];
        $billed = $this->pdo->prepare("SELECT COUNT(*) FROM erp_reservations r WHERE r.condominium_id = ? AND r.deleted_at IS NULL AND r.status = 'confirmed'
            AND r.fee_cents > 0 AND r.reserved_on BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM erp_extra_charges x WHERE x.reservation_id = r.id AND x.kind = 'extra' AND x.status = 'active')");
        $billed->execute([$c, $from, $to]);
        $result['already_billed'] = (int)$billed->fetchColumn();

        $shortcuts = new EntryShortcuts($this->pdo, $this->scope);
        $charges = [];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT r.id, r.unit_id, r.reserved_on, r.fee_cents, a.name AS area FROM erp_reservations r JOIN erp_common_areas a ON a.id = r.common_area_id
                WHERE r.condominium_id = ? AND r.deleted_at IS NULL AND r.status = 'confirmed' AND r.fee_cents > 0 AND r.reserved_on BETWEEN ? AND ?
                  AND NOT EXISTS (SELECT 1 FROM erp_extra_charges x WHERE x.reservation_id = r.id AND x.kind = 'extra' AND x.status = 'active')
                ORDER BY r.reserved_on, r.id LIMIT " . (self::MAX_RESERVATIONS + 1) . ' FOR UPDATE');
            $stmt->execute([$c, $from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > self::MAX_RESERVATIONS) {
                throw new InvalidArgumentException(sprintf('Mais de %d reservas a cobrar: reduza o período.', self::MAX_RESERVATIONS));
            }
            foreach ($rows as $row) {
                $on = substr((string)$row['reserved_on'], 0, 10);
                try {
                    $charge = $shortcuts->createCharge($user, ['condominium_id' => $c, 'kind' => 'extra', 'method' => 'fixed', 'unit_ids' => [(int)$row['unit_id']],
                        'amount_cents' => (int)$row['fee_cents'], 'description' => self::description((string)$row['area'], $on), 'competence' => substr($on, 0, 7),
                        'due_on' => $dueOn, 'chart_account_id' => $input['chart_account_id'] ?? null, 'reservation_id' => (int)$row['id']]);
                } catch (InvalidArgumentException | DomainException $error) {
                    throw new RegistryValidationException(['_form' => $error->getMessage(), '_reservation' => (string)$row['id']]);
                }
                $result['reservations'][] = ['id' => (int)$row['id'], 'area' => (string)$row['area'], 'reserved_on' => $on, 'fee_cents' => (int)$row['fee_cents'],
                    'charge_id' => $dryRun ? null : (int)$charge['id']];
                $result['total_cents'] += (int)$row['fee_cents'];
                $charges[(int)$row['id']] = (int)$charge['id'];
            }
            $result['count'] = count($result['reservations']);
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($charges as $reservation => $charge) {
                Audit::record('bill_fee', 'erp_reservations', $reservation, null, ['extra_charge_id' => $charge, 'from' => $from, 'to' => $to, 'batch' => $result['count']]);
            }
        }
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
