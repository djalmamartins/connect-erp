<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use DomainException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Honorários da administradora e pedidos de NFS-e (ADR 0402).
 *
 * Cada condomínio do escopo tem no máximo um contrato de administração com a Connect: valor mensal,
 * início, fim opcional, dia de vencimento, descrição do serviço e situação. A lista mostra o total
 * mensal dos contratos ativos (o controle "tipo assinatura" que o Djalma pediu) e, por competência,
 * se a nota já foi pedida. Não é cobrança de SaaS (ADR-002): é a relação da Connect com os condomínios
 * que ela administra.
 *
 * Pedir a nota só registra o pedido (status requested) com o valor e a descrição do contrato; o envio
 * à API Nacional da NFS-e é a etapa seguinte. Tudo depende da chave nfse.enabled (ErpSettings), que
 * nasce desligada: desligada, nenhum pedido é aceito. Um pedido aberto por contrato e competência.
 * Condomínio fora do escopo = RegistryNotFound. Toda gravação é auditada, sem valores pessoais.
 */
final class ManagementFees
{
    public const STATUSES = ['active' => 'Ativo', 'suspended' => 'Suspenso', 'ended' => 'Encerrado'];
    public const REQUEST_STATUSES = ['requested' => 'Solicitada', 'issued' => 'Emitida', 'failed' => 'Com erro', 'cancelled' => 'Cancelada'];
    public const DEFAULT_SERVICE = 'Honorários de administração condominial';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** A chave geral das notas está ligada? */
    public function enabled(): bool
    {
        return ErpSettings::value($this->pdo, null, 'nfse.enabled') === true;
    }

    /**
     * Condomínios do escopo com o contrato (ou null), o pedido da competência e o total mensal ativo.
     *
     * @return array{competence:string,nfse_enabled:bool,monthly_total_cents:int,active_contracts:int,rows:list<array<string,mixed>>}
     */
    public function overview(User $user, ?string $competence = null): array
    {
        $competence = self::competence($competence ?? date('Y-m'));
        $ids = $this->scope->allowedIds($user);
        $rows = [];
        $total = 0;
        $active = 0;
        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));
            $stmt = $this->pdo->prepare("SELECT c.id AS condominium_id, c.name AS condominium_name, m.id, m.monthly_fee_cents, m.start_date, m.end_date,
                    m.due_day, m.service_description, m.status, m.notes, m.updated_at,
                    r.id AS request_id, r.status AS request_status, r.amount_cents AS request_amount_cents, r.created_at AS requested_at
                FROM condominiums c
                LEFT JOIN erp_management_contracts m ON m.condominium_id = c.id
                LEFT JOIN erp_nfse_requests r ON r.contract_id = m.id AND r.competence = :competence AND r.status <> 'cancelled'
                WHERE c.id IN ({$in}) ORDER BY c.name, c.id");
            $stmt->execute(['competence' => $competence]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $contract = $row['id'] === null ? null : self::contractRow($row);
                $billable = $contract !== null && self::billable($contract, $competence);
                if ($contract !== null && $contract['status'] === 'active') {
                    $active++;
                    $total += $contract['monthly_fee_cents'];
                }
                $rows[] = [
                    'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => (string)$row['condominium_name'],
                    'contract' => $contract, 'billable' => $billable,
                    'request' => $row['request_id'] === null ? null : ['id' => (int)$row['request_id'], 'status' => (string)$row['request_status'],
                        'amount_cents' => (int)$row['request_amount_cents'], 'requested_at' => (string)$row['requested_at']],
                ];
            }
        }
        return ['competence' => $competence, 'nfse_enabled' => $this->enabled(), 'monthly_total_cents' => $total, 'active_contracts' => $active, 'rows' => $rows];
    }

    /** Contrato do condomínio, ou null. */
    public function contract(User $user, int $condominiumId): ?array
    {
        $this->assertScope($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT * FROM erp_management_contracts WHERE condominium_id = :c');
        $stmt->execute(['c' => $condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::contractRow($row);
    }

    /**
     * Cria ou altera o contrato do condomínio (tudo validado de novo), com auditoria.
     *
     * @param array<string, mixed> $input monthly_fee, start_date, end_date?, due_day, service_description?, status?, notes?
     */
    public function save(User $user, int $condominiumId, array $input): array
    {
        $this->assertScope($user, $condominiumId);
        $data = self::validate($input);
        $before = $this->contract($user, $condominiumId);
        $params = $data + ['c' => $condominiumId, 'u' => (int)$user->id];
        if ($before === null) {
            $this->pdo->prepare('INSERT INTO erp_management_contracts (condominium_id, monthly_fee_cents, start_date, end_date, due_day, service_description, status, notes, created_by, updated_by)
                VALUES (:c, :monthly_fee_cents, :start_date, :end_date, :due_day, :service_description, :status, :notes, :u, :u)')->execute($params);
        } else {
            $this->pdo->prepare('UPDATE erp_management_contracts SET monthly_fee_cents = :monthly_fee_cents, start_date = :start_date, end_date = :end_date,
                due_day = :due_day, service_description = :service_description, status = :status, notes = :notes, updated_by = :u WHERE condominium_id = :c')->execute($params);
        }
        $after = (array)$this->contract($user, $condominiumId);
        Audit::record($before === null ? 'create' : 'update', 'erp_management_contracts', $after['id'], $before === null ? null : self::auditContract($before), self::auditContract($after));
        return $after;
    }

    /**
     * Pede a nota da competência: exige a chave ligada, contrato ativo e competência dentro do contrato.
     * O valor e a descrição são os do contrato no momento do pedido. Um pedido aberto por competência.
     */
    public function request(User $user, int $condominiumId, mixed $competence): array
    {
        $this->assertScope($user, $condominiumId);
        if (!$this->enabled()) {
            throw new DomainException('As notas fiscais estão desligadas. Ligue em Configurações › Financeiro para solicitar notas.');
        }
        $competence = self::competence($competence);
        $contract = $this->contract($user, $condominiumId) ?? throw new RegistryValidationException(['_contract' => 'Cadastre os honorários do condomínio antes de pedir a nota.']);
        if ($contract['status'] !== 'active') {
            throw new RegistryValidationException(['_contract' => 'O contrato não está ativo.']);
        }
        if (!self::billable($contract, $competence)) {
            throw new RegistryValidationException(['competence' => 'A competência está fora do período do contrato.']);
        }
        try {
            $this->pdo->prepare('INSERT INTO erp_nfse_requests (contract_id, condominium_id, competence, amount_cents, service_description, requested_by)
                VALUES (:contract, :c, :competence, :amount, :service, :u)')->execute([
                'contract' => $contract['id'], 'c' => $condominiumId, 'competence' => $competence,
                'amount' => $contract['monthly_fee_cents'], 'service' => $contract['service_description'], 'u' => (int)$user->id,
            ]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                throw new DomainException('A nota desta competência já foi solicitada.');
            }
            throw $exception;
        }
        $id = (int)$this->pdo->lastInsertId();
        $request = $this->findRequest($id);
        Audit::record('create', 'erp_nfse_requests', $id, null, ['condominium_id' => $condominiumId, 'competence' => $competence,
            'amount_cents' => $request['amount_cents'], 'status' => 'requested']);
        return $request;
    }

    /** Cancela um pedido ainda não emitido (libera a competência para novo pedido), com motivo e auditoria. */
    public function cancel(User $user, int $requestId, mixed $reason): array
    {
        $request = $this->findRequest($requestId);
        $this->assertScope($user, $request['condominium_id']);
        $text = is_string($reason) ? trim((string)preg_replace('/[\p{C}]+/u', ' ', $reason)) : '';
        if (mb_strlen($text) < 3 || mb_strlen($text) > 255) {
            throw new RegistryValidationException(['reason' => 'Informe o motivo do cancelamento (3 a 255 caracteres).']);
        }
        if ($request['status'] === 'issued') {
            throw new DomainException('Nota já emitida: o cancelamento é feito na Receita, na etapa de emissão.');
        }
        $stmt = $this->pdo->prepare("UPDATE erp_nfse_requests SET status = 'cancelled', cancel_reason = :reason, cancelled_by = :u, cancelled_at = NOW()
            WHERE id = :id AND status IN ('requested','failed')");
        $stmt->execute(['reason' => $text, 'u' => (int)$user->id, 'id' => $requestId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('O pedido mudou de situação. Atualize a página.');
        }
        Audit::record('update', 'erp_nfse_requests', $requestId, ['status' => $request['status']], ['status' => 'cancelled']);
        return $this->findRequest($requestId);
    }

    /** Pedidos de nota do condomínio, do mais novo ao mais antigo. */
    public function requests(User $user, int $condominiumId): array
    {
        $this->assertScope($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT * FROM erp_nfse_requests WHERE condominium_id = :c ORDER BY competence DESC, id DESC LIMIT 120');
        $stmt->execute(['c' => $condominiumId]);
        return array_map([self::class, 'requestRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Entrada validada (regra pura).
     *
     * @return array{monthly_fee_cents:int,start_date:string,end_date:?string,due_day:int,service_description:string,status:string,notes:?string}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $fee = $input['monthly_fee'] ?? $input['monthly_fee_cents'] ?? null;
        $cents = is_int($fee) ? Invoices::cents($fee) : (is_string($fee) ? Invoices::cents($fee) : null);
        if ($cents === null) {
            $errors['monthly_fee'] = 'Informe o valor mensal (maior que zero).';
        }
        $start = self::date($input['start_date'] ?? null);
        if ($start === null) {
            $errors['start_date'] = 'Informe o início do contrato (AAAA-MM-DD).';
        }
        $endRaw = $input['end_date'] ?? null;
        $end = $endRaw === null || $endRaw === '' ? null : self::date($endRaw);
        if ($endRaw !== null && $endRaw !== '' && $end === null) {
            $errors['end_date'] = 'Data de fim inválida (AAAA-MM-DD).';
        } elseif ($end !== null && $start !== null && $end < $start) {
            $errors['end_date'] = 'O fim não pode ser antes do início.';
        }
        $due = filter_var($input['due_day'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
        if ($due === false) {
            $errors['due_day'] = 'Dia de vencimento de 1 a 28.';
        }
        $service = self::text($input['service_description'] ?? '');
        $service = $service === '' ? self::DEFAULT_SERVICE : $service;
        if (mb_strlen($service) < 3 || mb_strlen($service) > 255) {
            $errors['service_description'] = 'Descrição do serviço de 3 a 255 caracteres.';
        }
        $status = (string)($input['status'] ?? 'active');
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'Situação inválida.';
        }
        $notes = self::text($input['notes'] ?? '');
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Observações com no máximo 500 caracteres.';
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }
        return ['monthly_fee_cents' => (int)$cents, 'start_date' => (string)$start, 'end_date' => $end, 'due_day' => (int)$due,
            'service_description' => $service, 'status' => $status, 'notes' => $notes === '' ? null : $notes];
    }

    /** A competência (AAAA-MM) cai dentro do contrato? (regra pura) */
    public static function billable(array $contract, string $competence): bool
    {
        $first = $competence . '-01';
        $last = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        return $contract['start_date'] <= $last && ($contract['end_date'] === null || $contract['end_date'] >= $first);
    }

    /** Competência AAAA-MM válida (regra pura). */
    public static function competence(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) || (int)substr($value, 0, 4) < 2000) {
            throw new RegistryValidationException(['competence' => 'Competência inválida (AAAA-MM).']);
        }
        return $value;
    }

    private function findRequest(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_nfse_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RegistryNotFound();
        }
        return self::requestRow($row);
    }

    private function assertScope(User $user, int $condominiumId): void
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
    }

    private static function contractRow(array $row): array
    {
        return ['id' => (int)$row['id'], 'condominium_id' => (int)($row['condominium_id']), 'monthly_fee_cents' => (int)$row['monthly_fee_cents'],
            'start_date' => (string)$row['start_date'], 'end_date' => $row['end_date'] === null ? null : (string)$row['end_date'],
            'due_day' => (int)$row['due_day'], 'service_description' => (string)$row['service_description'], 'status' => (string)$row['status'],
            'notes' => $row['notes'] === null ? null : (string)$row['notes'], 'updated_at' => (string)$row['updated_at']];
    }

    private static function requestRow(array $row): array
    {
        return ['id' => (int)$row['id'], 'contract_id' => (int)$row['contract_id'], 'condominium_id' => (int)$row['condominium_id'],
            'competence' => (string)$row['competence'], 'amount_cents' => (int)$row['amount_cents'], 'service_description' => (string)$row['service_description'],
            'status' => (string)$row['status'], 'nfse_number' => $row['nfse_number'], 'cancel_reason' => $row['cancel_reason'],
            'requested_at' => (string)$row['created_at'], 'cancelled_at' => $row['cancelled_at']];
    }

    private static function auditContract(array $contract): array
    {
        return array_intersect_key($contract, array_flip(['condominium_id', 'monthly_fee_cents', 'start_date', 'end_date', 'due_day', 'status']));
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim((string)preg_replace('/[\p{C}]+/u', ' ', $value)) : '';
    }
}
