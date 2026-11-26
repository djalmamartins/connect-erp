<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Faturas (AP-032, ADR 0033): cobrança de um tipo de fatura a todas as unidades ativas de um
 * condomínio numa competência.
 *
 * - prepare(): rascunho com um item por unidade participante. Formas de rateio (ADR 0640): valor fixo por unidade
 *   (igualitário), total dividido pelo número de unidades, pela fração ideal, pelo percentual definido da unidade,
 *   pelas vagas de garagem vinculadas ou personalizado (valor ou percentual de cada unidade). Participam todas as
 *   unidades ativas (menos as isentas de rateio), só as de alguns blocos ou torres, ou só as escolhidas. A soma dos
 *   itens é sempre o total pedido (Invoices::apportion). Uma fatura ativa por condomínio, tipo e competência (409).
 * - setItemAmount(): ajusta o valor de uma unidade enquanto é rascunho.
 * - issue(): cada item vira uma conta a receber (erp_entries) da unidade, pelo RegistryService (mesmas
 *   regras dos lançamentos, inclusive a trava do fechamento). Tudo ou nada; a chave única em
 *   erp_entries.invoice_item_id barra emitir duas vezes.
 * - cancel(): exige motivo. Emitida só cancela se nenhuma conta a receber foi paga e a competência
 *   não está fechada; os lançamentos gerados ficam cancelados (status inactive).
 * - Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class Invoices
{
    public const STATUSES = ['draft' => 'Em preparação', 'issued' => 'Emitida', 'cancelled' => 'Cancelada'];
    public const METHODS = ['fixed' => 'Valor fixo por unidade (igualitário)', 'equal' => 'Total dividido pelo número de unidades', 'fraction' => 'Rateio pela fração ideal',
        'percent' => 'Rateio pelo percentual definido da unidade', 'parking' => 'Rateio pelas vagas de garagem', 'custom' => 'Valor ou percentual de cada unidade (personalizado)'];
    /** Quem participa do rateio (ADR 0640). */
    public const PARTICIPANTS = ['all' => 'Todas as unidades (menos as isentas de rateio)', 'blocks' => 'Só os blocos ou torres escolhidos', 'units' => 'Só as unidades escolhidas'];
    /** Formas em que o valor informado é o total a ratear (no valor fixo é por unidade; no personalizado com valores, não há total). */
    public const SPLIT_METHODS = ['equal', 'fraction', 'percent', 'parking'];
    public const MAX_BLOCKS = 30;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = $registry ?? new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Rateio de um total pelas frações (regra pura): cada parte é arredondada para baixo e os
     * centavos que sobram vão, um a um, para as maiores sobras (empate: ordem de entrada). A soma
     * das partes é sempre igual ao total.
     *
     * @param array<int|string, float|int|string> $fractions chave => fração (> 0)
     * @return array<int|string, int> chave => centavos
     */
    public static function apportion(int $totalCents, array $fractions): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException('Informe um valor total maior que zero.');
        }
        $sum = 0.0;
        foreach ($fractions as $fraction) {
            if (!is_numeric($fraction) || (float)$fraction <= 0) {
                throw new InvalidArgumentException('Todas as unidades precisam de fração ideal maior que zero para o rateio.');
            }
            $sum += (float)$fraction;
        }
        if ($fractions === [] || $sum <= 0) {
            throw new InvalidArgumentException('Não há unidades para ratear.');
        }
        $shares = [];
        $remainders = [];
        $position = 0;
        foreach ($fractions as $key => $fraction) {
            $exact = $totalCents * ((float)$fraction / $sum);
            $shares[$key] = (int)floor($exact + 1e-9);
            $remainders[] = [$key, $exact - $shares[$key], $position++];
        }
        $left = $totalCents - array_sum($shares);
        usort($remainders, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        for ($i = 0; $i < $left; $i++) {
            $shares[$remainders[$i % count($remainders)][0]]++;
        }
        return $shares;
    }

    /**
     * Valor de cada unidade participante pela forma de rateio (regra pura, ADR 0640). A soma é sempre o total pedido.
     *
     * - fixed: $amountCents para cada unidade; equal: total dividido igual; fraction: pela fração ideal;
     * - percent: pelo percentual definido da unidade, que precisa somar 100% entre as participantes;
     * - parking: pelas vagas de garagem vinculadas (unidade sem vaga fica fora do rateio);
     * - custom: o valor informado de cada unidade ($amounts) ou, sem valores, o percentual informado ($percents), que
     *   precisa somar 100%.
     *
     * @param list<array{id:int|string, label?:string, ideal_fraction?:mixed, apportion_percent?:mixed, parking_spots?:int|string}> $units
     * @param array<int|string, mixed> $amounts centavos (int) ou reais em texto, por id de unidade
     * @param array<int|string, mixed> $percents percentual por id de unidade
     * @return array<int, int> centavos por id de unidade (só as que participam)
     */
    public static function distribute(string $method, ?int $amountCents, array $units, array $amounts = [], array $percents = []): array
    {
        if (!isset(self::METHODS[$method])) {
            throw new InvalidArgumentException('Forma de cálculo inválida.');
        }
        if ($units === []) {
            throw new InvalidArgumentException('Não há unidades participantes para ratear.');
        }
        $label = static fn (array $u): string => (string)($u['label'] ?? $u['id']);
        $ids = array_map(static fn (array $u): int => (int)$u['id'], $units);
        if ($method === 'custom' && $amounts !== []) {
            $out = [];
            foreach ($units as $u) {
                $cents = self::cents($amounts[(int)$u['id']] ?? $amounts[(string)$u['id']] ?? null);
                if ($cents === null) {
                    throw new InvalidArgumentException('Informe o valor de cada unidade participante (falta em ' . $label($u) . ').');
                }
                $out[(int)$u['id']] = $cents;
            }
            return $out;
        }
        if ($amountCents === null || $amountCents <= 0) {
            throw new InvalidArgumentException($method === 'fixed' ? 'Informe o valor por unidade.' : 'Informe o valor total a ratear.');
        }
        if ($method === 'fixed') {
            return array_fill_keys($ids, $amountCents);
        }
        $weights = [];
        $missing = [];
        foreach ($units as $u) {
            $id = (int)$u['id'];
            $weight = match ($method) {
                'equal' => 1,
                'fraction' => $u['ideal_fraction'] ?? null,
                'percent' => $u['apportion_percent'] ?? null,
                'parking' => (int)($u['parking_spots'] ?? 0),
                'custom' => self::percent($percents[$id] ?? $percents[(string)$id] ?? null),
            };
            if ($method === 'parking' && $weight === 0) {
                continue;   // sem vaga vinculada, não entra no rateio das vagas
            }
            if (!is_numeric($weight) || (float)$weight <= 0) {
                $missing[] = $label($u);
                continue;
            }
            $weights[$id] = $weight;
        }
        if ($missing !== []) {
            $what = ['fraction' => 'fração ideal', 'percent' => 'percentual definido', 'custom' => 'percentual'][$method] ?? 'peso';
            throw new InvalidArgumentException(sprintf('Sem %s maior que zero: %s.', $what, implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' e mais ' . (count($missing) - 10) : '')));
        }
        if ($weights === []) {
            throw new InvalidArgumentException('Nenhuma unidade participante tem vaga de garagem vinculada (cadastre as vagas em Áreas vinculáveis).');
        }
        if (in_array($method, ['percent', 'custom'], true)) {
            $sum = round(array_sum(array_map('floatval', $weights)), 4);
            if (abs($sum - 100.0) > 0.0001) {
                throw new InvalidArgumentException(sprintf('Os percentuais das unidades participantes somam %s%%; precisam somar 100%%.', number_format($sum, 4, ',', '.')));
            }
        }
        if ($amountCents < count($weights)) {
            throw new InvalidArgumentException('O total é menor que um centavo por unidade.');
        }
        return self::apportion($amountCents, $weights);
    }

    /** Percentual em número ou texto ("12,5"); inválido = null. */
    private static function percent(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $text = is_string($value) ? str_replace(',', '.', trim($value)) : '';
        return $text !== '' && is_numeric($text) ? (float)$text : null;
    }

    /**
     * Blocos escolhidos (regra pura): lista ou texto separado por vírgula, sem repetição nem vazios, até MAX_BLOCKS.
     *
     * @return list<string>
     */
    public static function blocks(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);
        $out = [];
        foreach ($list as $value) {
            $block = is_scalar($value) ? trim((string)$value) : '';
            if ($block !== '') {
                $out[mb_strtolower($block)] ??= mb_substr($block, 0, 20);
            }
        }
        if ($out === []) {
            throw new InvalidArgumentException('Escolha ao menos um bloco ou torre.');
        }
        if (count($out) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException('Escolha no máximo ' . self::MAX_BLOCKS . ' blocos.');
        }
        return array_values($out);
    }

    /** Teto de um valor em centavos, o mesmo do motor de cadastros (RegistryValidator, campo money). */
    public const MAX_CENTS = 99_999_999_999;

    /** Centavos a partir de int (centavos) ou texto em reais ("1.234,56"); inválido, zero ou acima do teto = null (ADR 0062). */
    public static function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= self::MAX_CENTS ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', trim($value))) {
            return null;
        }
        [$int, $dec] = array_pad(explode(',', str_replace('.', '', trim($value))), 2, '0');
        if (strlen(ltrim($int, '0')) > 10) {
            return null;   // acima do teto; barra antes de estourar o inteiro do PHP
        }
        $cents = (int)$int * 100 + (int)str_pad(substr($dec, 0, 2), 2, '0');
        return $cents > 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }

    /**
     * @param array{condominium_id?:mixed, invoice_type_id?:mixed, competence?:mixed, due_on?:mixed, method?:mixed, amount_cents?:mixed, notes?:mixed} $input
     */
    public function prepare(User $user, array $input): array
    {
        $condominium = $this->int($input['condominium_id'] ?? null);
        $typeId = $this->int($input['invoice_type_id'] ?? null);
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        $method = is_string($input['method'] ?? null) && isset(self::METHODS[$input['method']]) ? $input['method'] : 'fixed';
        $participants = is_string($input['participants'] ?? null) && isset(self::PARTICIPANTS[$input['participants']]) ? $input['participants'] : 'all';
        $notes = is_string($input['notes'] ?? null) ? mb_substr(trim($input['notes']), 0, 255) : null;

        if ($condominium === null || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($date === false || $date->format('Y-m-d') !== $dueOn) {
            throw new InvalidArgumentException('Informe o vencimento (AAAA-MM-DD).');
        }
        $type = $this->invoiceType($typeId, $condominium);
        if ($this->registry->isClosed($condominium, $competence)) {
            throw new DomainException("A competência {$competence} está fechada. Reabra o fechamento mensal para faturar.");
        }
        $customAmounts = $method === 'custom' && is_array($input['unit_amounts'] ?? null) ? array_filter($input['unit_amounts'], static fn ($v): bool => $v !== null && $v !== '') : [];
        $amount = array_key_exists('amount_cents', $input) && $input['amount_cents'] !== null && $input['amount_cents'] !== ''
            ? self::cents($input['amount_cents'])
            : ((int)$type['default_amount_cents'] > 0 ? (int)$type['default_amount_cents'] : null);
        if ($amount === null && $customAmounts === []) {
            throw new InvalidArgumentException($method === 'fixed' ? 'Informe o valor por unidade (o tipo de fatura não tem valor padrão).'
                : ($method === 'custom' ? 'Informe o valor de cada unidade ou o total a ratear pelos percentuais.' : 'Informe o valor total a ratear.'));
        }

        // Participantes (ADR 0640): todas, os blocos escolhidos ou as unidades escolhidas. Unidade isenta de rateio (ADR 0311)
        // fica fora das duas primeiras; escolhida uma a uma, entra. A fração e o percentual são normalizados no apportion().
        $where = "u.condominium_id = :c AND u.deleted_at IS NULL AND u.status = 'active'";
        $params = ['c' => $condominium];
        $blocks = null;
        if ($participants === 'units') {
            $chosen = EntryShortcuts::unitIds($input['unit_ids'] ?? null);
            $where .= ' AND u.id IN (' . implode(',', $chosen) . ')';
        } else {
            $where .= ' AND u.apportionment_exempt = 0';
            if ($participants === 'blocks') {
                $blocks = self::blocks($input['blocks'] ?? null);
                $marks = [];
                foreach ($blocks as $i => $block) {
                    $marks[] = ':b' . $i;
                    $params['b' . $i] = $block;
                }
                $where .= ' AND u.block IN (' . implode(',', $marks) . ')';
            }
        }
        $stmt = $this->pdo->prepare("SELECT u.id, u.label, u.block, u.ideal_fraction, u.apportion_percent,
                (SELECT COUNT(*) FROM erp_linkable_areas a WHERE a.unit_id = u.id AND a.kind = 'vaga' AND a.status = 'active' AND a.deleted_at IS NULL) AS parking_spots
            FROM erp_units u WHERE {$where} ORDER BY u.label, u.id");
        $stmt->execute($params);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($units === []) {
            throw new InvalidArgumentException('O condomínio não tem unidades ativas (fora as isentas de rateio) para faturar.');
        }
        if ($participants === 'units' && count($units) !== count($chosen)) {
            throw new InvalidArgumentException('Alguma unidade escolhida não existe, está inativa ou não é deste condomínio.');
        }
        if ($blocks !== null) {
            $found = array_unique(array_map(static fn (array $u): string => mb_strtolower((string)$u['block']), $units));
            $absent = array_filter($blocks, static fn (string $b): bool => !in_array(mb_strtolower($b), $found, true));
            if ($absent !== []) {
                throw new InvalidArgumentException('Sem unidades ativas no bloco ou torre: ' . implode(', ', $absent) . '.');
            }
        }
        $amounts = self::distribute($method, $amount, $units, $customAmounts, is_array($input['unit_percents'] ?? null) ? $input['unit_percents'] : []);
        $units = array_values(array_filter($units, static fn (array $u): bool => isset($amounts[(int)$u['id']])));
        $requested = in_array($method, self::SPLIT_METHODS, true) || ($method === 'custom' && $customAmounts === []) ? $amount : null;

        $this->pdo->beginTransaction();
        try {
            // Reconfere com leitura travada, na transação (ADR 0100); a conferência acima só adianta o erro.
            if ($this->registry->isClosed($condominium, $competence)) {
                throw new DomainException("A competência {$competence} está fechada. Reabra o fechamento mensal para faturar.");
            }
            $this->pdo->prepare('INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, method, participants, participant_blocks, requested_cents, status, notes, created_by)
                VALUES (:c, :t, :m, :d, :method, :who, :blocks, :requested, \'draft\', :n, :u)')
                ->execute(['c' => $condominium, 't' => $typeId, 'm' => $competence, 'd' => $dueOn, 'method' => $method, 'who' => $participants,
                    'blocks' => $blocks === null ? null : mb_substr(implode(', ', $blocks), 0, 255), 'requested' => $requested, 'n' => $notes, 'u' => (int)$user->id]);
            $invoiceId = (int)$this->pdo->lastInsertId();
            $item = $this->pdo->prepare('INSERT INTO erp_invoice_items (invoice_id, unit_id, unit_label, amount_cents) VALUES (:i, :u, :l, :a)');
            foreach ($units as $unit) {
                $item->execute(['i' => $invoiceId, 'u' => (int)$unit['id'], 'l' => mb_substr((string)$unit['label'], 0, 40), 'a' => $amounts[(int)$unit['id']]]);
            }
            $this->refreshTotals($invoiceId);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException("Já existe uma fatura de {$type['name']} para {$competence} neste condomínio. Cancele-a para preparar outra.");
            }
            throw $error;
        }
        $invoice = $this->find($user, $invoiceId);
        Audit::record('create', 'erp_invoices', $invoiceId, null, ['status' => 'draft', 'competence' => $competence, 'invoice_type_id' => $typeId,
            'method' => $method, 'participants' => $participants, 'blocks' => $blocks, 'requested_cents' => $requested, 'units_count' => $invoice['units_count'], 'amount_cents' => $invoice['amount_cents']]);
        return $invoice;
    }

    public function setItemAmount(User $user, int $invoiceId, int $itemId, mixed $amount): array
    {
        $invoice = $this->invoiceRow($user, $invoiceId);
        if ($invoice['status'] !== 'draft') {
            throw new DomainException('Só é possível ajustar valores de fatura em preparação.');
        }
        $cents = self::cents($amount);
        if ($cents === null) {
            throw new InvalidArgumentException('Informe um valor maior que zero.');
        }
        $stmt = $this->pdo->prepare('SELECT amount_cents FROM erp_invoice_items WHERE id = :id AND invoice_id = :invoice');
        $stmt->execute(['id' => $itemId, 'invoice' => $invoiceId]);
        $before = $stmt->fetchColumn();
        if ($before === false) {
            throw new RegistryNotFound();
        }
        $this->pdo->prepare('UPDATE erp_invoice_items SET amount_cents = :a WHERE id = :id')->execute(['a' => $cents, 'id' => $itemId]);
        $this->refreshTotals($invoiceId);
        Audit::record('update', 'erp_invoice_items', $itemId, ['amount_cents' => (int)$before], ['amount_cents' => $cents]);
        return $this->find($user, $invoiceId);
    }

    public function issue(User $user, int $invoiceId): array
    {
        $this->invoiceRow($user, $invoiceId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT i.*, t.name AS type_name, t.chart_account_id FROM erp_invoices i
                JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE i.id = :id FOR UPDATE');
            $stmt->execute(['id' => $invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice['status'] !== 'draft') {
                throw new DomainException('Só fatura em preparação pode ser emitida.');
            }
            $items = $this->pdo->prepare('SELECT * FROM erp_invoice_items WHERE invoice_id = :id ORDER BY unit_label, id');
            $items->execute(['id' => $invoiceId]);
            $link = $this->pdo->prepare('UPDATE erp_entries SET invoice_item_id = :item WHERE id = :id');
            $outbox = new NotificationOutbox($this->pdo);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $invoice['condominium_id'], 'nature' => 'receita', 'chart_account_id' => $invoice['chart_account_id'],
                    'description' => mb_substr("{$invoice['type_name']} {$invoice['competence']} — {$item['unit_label']}", 0, 160),
                    'amount_cents' => number_format(((int)$item['amount_cents']) / 100, 2, ',', ''),
                    'competence' => $invoice['competence'], 'due_on' => $invoice['due_on'], 'unit_id' => $item['unit_id'],
                    'document_number' => 'FAT-' . $invoiceId,
                ]);
                $link->execute(['item' => (int)$item['id'], 'id' => (int)$entry['id']]);
                // Aviso da cobrança à unidade (ADR 0056), na mesma transação: só vai para quem autorizou (opt-in).
                $outbox->record('invoice.issued', 'invoice.issued:' . $item['id'], (int)$invoice['condominium_id'], 'erp_invoice_items', (int)$item['id'], [
                    'unit_id' => (int)$item['unit_id'], 'unit_label' => (string)$item['unit_label'], 'type_name' => (string)$invoice['type_name'],
                    'competence' => (string)$invoice['competence'], 'due_on' => (string)$invoice['due_on'], 'amount_cents' => (int)$item['amount_cents'],
                ], (int)$user->id);
            }
            $this->pdo->prepare("UPDATE erp_invoices SET status = 'issued', issued_by = :u, issued_at = NOW() WHERE id = :id")
                ->execute(['u' => (int)$user->id, 'id' => $invoiceId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('Esta fatura já foi emitida.');
            }
            throw $error;
        }
        Audit::record('update', 'erp_invoices', $invoiceId, ['status' => 'draft'], ['status' => 'issued']);
        return $this->find($user, $invoiceId);
    }

    public function cancel(User $user, int $invoiceId, string $reason): array
    {
        $invoice = $this->invoiceRow($user, $invoiceId);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento (5 a 255 caracteres).');
        }
        if ($invoice['status'] === 'cancelled') {
            throw new DomainException('A fatura já está cancelada.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($invoice['status'] === 'issued') {
                if ($this->registry->isClosed($invoice['condominium_id'], $invoice['competence'])) {
                    throw new DomainException("A competência {$invoice['competence']} está fechada. Reabra o fechamento mensal para cancelar a fatura.");
                }
                $paid = $this->pdo->prepare("SELECT COUNT(*) FROM erp_entries e JOIN erp_invoice_items it ON it.id = e.invoice_item_id
                    WHERE it.invoice_id = :id AND e.paid_on IS NOT NULL AND e.deleted_at IS NULL AND e.status = 'active' FOR UPDATE");
                $paid->execute(['id' => $invoiceId]);
                if ((int)$paid->fetchColumn() > 0) {
                    throw new DomainException('Há contas a receber desta fatura já pagas. Estorne a baixa antes de cancelar.');
                }
                // Conta renegociada em acordo ativo (ADR 0039): cancele o acordo antes.
                $renegotiated = $this->pdo->prepare("SELECT COUNT(*) FROM erp_entries e JOIN erp_invoice_items it ON it.id = e.invoice_item_id
                    WHERE it.invoice_id = :id AND e.agreement_id IS NOT NULL AND e.deleted_at IS NULL");
                $renegotiated->execute(['id' => $invoiceId]);
                if ((int)$renegotiated->fetchColumn() > 0) {
                    throw new DomainException('Há contas desta fatura renegociadas em acordo. Cancele o acordo antes.');
                }
                $this->pdo->prepare("UPDATE erp_entries e JOIN erp_invoice_items it ON it.id = e.invoice_item_id
                    SET e.status = 'inactive', e.updated_by = :u WHERE it.invoice_id = :id AND e.deleted_at IS NULL")
                    ->execute(['u' => (int)$user->id, 'id' => $invoiceId]);
            }
            $this->pdo->prepare("UPDATE erp_invoices SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r
                WHERE id = :id AND status <> 'cancelled'")->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $invoiceId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_invoices', $invoiceId, ['status' => $invoice['status']], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->find($user, $invoiceId);
    }

    /**
     * Faturas do escopo; $condominiumId null = todos os condomínios do usuário (contexto "Todos").
     *
     * @param array{competence?:mixed, status?:mixed, page?:mixed} $filters
     * @return array{data: list<array>, total:int, page:int, per_page:int, pages:int}
     */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $perPage = 25;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = ['i.condominium_id IN (' . implode(',', array_map('intval', $ids)) . ')'];
        $params = [];
        if (is_string($filters['competence'] ?? null) && RecurringEntries::isCompetence($filters['competence'])) {
            $where[] = 'i.competence = :m';
            $params['m'] = $filters['competence'];
        }
        if (is_string($filters['status'] ?? null) && isset(self::STATUSES[$filters['status']])) {
            $where[] = 'i.status = :s';
            $params['s'] = $filters['status'];
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_invoices i WHERE {$sqlWhere}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT i.*, c.name AS condominium_name, t.name AS type_name FROM erp_invoices i
            JOIN condominiums c ON c.id = i.condominium_id JOIN erp_invoice_types t ON t.id = i.invoice_type_id
            WHERE {$sqlWhere} ORDER BY i.competence DESC, c.name, t.name, i.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    /** Fatura com os itens e a situação da conta a receber de cada unidade. */
    public function find(User $user, int $invoiceId): array
    {
        $this->invoiceRow($user, $invoiceId);
        $stmt = $this->pdo->prepare('SELECT i.*, c.name AS condominium_name, t.name AS type_name FROM erp_invoices i
            JOIN condominiums c ON c.id = i.condominium_id JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE i.id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $invoice = $this->present($stmt->fetch(PDO::FETCH_ASSOC));
        $items = $this->pdo->prepare('SELECT it.id, it.unit_id, it.unit_label, it.amount_cents, e.id AS entry_id, e.paid_on, e.status AS entry_status
            FROM erp_invoice_items it LEFT JOIN erp_entries e ON e.invoice_item_id = it.id AND e.deleted_at IS NULL
            WHERE it.invoice_id = :id ORDER BY it.unit_label, it.id');
        $items->execute(['id' => $invoiceId]);
        $invoice['items'] = array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'unit_id' => (int)$r['unit_id'], 'unit_label' => (string)$r['unit_label'], 'amount_cents' => (int)$r['amount_cents'],
            'entry_id' => $r['entry_id'] !== null ? (int)$r['entry_id'] : null, 'paid_on' => $r['paid_on'],
            'entry_status' => $r['entry_status'],
        ], $items->fetchAll(PDO::FETCH_ASSOC));
        return $invoice;
    }

    /** Linha da fatura no escopo do usuário; fora dele ou inexistente = RegistryNotFound (404). */
    private function invoiceRow(User $user, int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    /** Tipo de fatura ativo do padrão da Connect ou do próprio condomínio. */
    private function invoiceType(?int $typeId, int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_invoice_types WHERE id = :id AND deleted_at IS NULL AND status = 'active'
            AND (condominium_id IS NULL OR condominium_id = :c)");
        $stmt->execute(['id' => (int)$typeId, 'c' => $condominium]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($type)) {
            throw new InvalidArgumentException('Escolha um tipo de fatura ativo do padrão ou deste condomínio.');
        }
        return $type;
    }

    private function refreshTotals(int $invoiceId): void
    {
        $this->pdo->prepare('UPDATE erp_invoices i SET
                units_count = (SELECT COUNT(*) FROM erp_invoice_items WHERE invoice_id = i.id),
                amount_cents = (SELECT COALESCE(SUM(amount_cents), 0) FROM erp_invoice_items WHERE invoice_id = i.id)
            WHERE i.id = :id')->execute(['id' => $invoiceId]);
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => $row['condominium_name'] ?? null,
            'invoice_type_id' => (int)$row['invoice_type_id'], 'type_name' => $row['type_name'] ?? null,
            'competence' => (string)$row['competence'], 'due_on' => (string)$row['due_on'],
            'status' => (string)$row['status'], 'status_label' => self::STATUSES[$row['status']] ?? (string)$row['status'],
            'units_count' => (int)$row['units_count'], 'amount_cents' => (int)$row['amount_cents'], 'notes' => $row['notes'],
            'method' => $row['method'] ?? null, 'method_label' => isset($row['method']) ? (self::METHODS[$row['method']] ?? null) : null,
            'participants' => $row['participants'] ?? null, 'participant_blocks' => $row['participant_blocks'] ?? null,
            'requested_cents' => isset($row['requested_cents']) ? (int)$row['requested_cents'] : null,
            'issued_at' => $row['issued_at'], 'cancelled_at' => $row['cancelled_at'], 'cancel_reason' => $row['cancel_reason'],
        ];
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
