<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Documentos em trânsito (ADR 0215), somente leitura. Os cadastros de malotes (AP-129) e de avisos de recebimento
 * (AP-126, ADR 0050) registram envio, recebimento e conferência, mas nada aponta o que está parado: o malote enviado
 * que ninguém recebeu, o recebido que ninguém conferiu e o AR que nunca voltou (prazo de defesa de uma notificação de
 * multa, por exemplo, depende do AR entregue).
 *
 * - Etapa de cada registro em aberto pela regra pura stage():
 *   - malote enviado (active) sem recebimento: awaiting_receipt, desde o envio;
 *   - malote recebido (received) sem conferência: awaiting_check, desde o recebimento;
 *   - AR em trânsito (active): in_transit, desde o envio;
 *   - conferido, entregue ou devolvido: fora (resolvido).
 * - Dias pela mesma conta dos atrasos (Agreements::daysLate); atrasado = mais dias que o limite (days, padrão 7).
 * - Nada muda: a baixa continua sendo pelo formulário de cada cadastro. Não grava e não envia nada.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, etapa, datas, dias,
 * quantidade de documentos do malote e a unidade do AR: nenhum portador, conteúdo, destinatário, assunto nem código de
 * rastreio (levam nome e endereço de pessoa).
 */
final class DocumentTransit
{
    public const MAX_OPEN = 2000;
    public const MAX_ITEMS = 500;
    public const DEFAULT_DAYS = 7;
    public const STAGES = ['awaiting_receipt', 'awaiting_check', 'in_transit'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Etapa em aberto e a data de início (regra pura); null = resolvido.
     *
     * @param array{status:string, sent_on:string, received_on?:?string, checked_on?:?string, delivered_on?:?string} $row
     * @return array{stage:string, since:string}|null
     */
    public static function stage(string $kind, array $row): ?array
    {
        if ($kind === 'pouch') {
            if ($row['status'] === 'active' && ($row['received_on'] ?? null) === null) {
                return ['stage' => 'awaiting_receipt', 'since' => $row['sent_on']];
            }
            if ($row['status'] === 'received' && ($row['checked_on'] ?? null) === null) {
                return ['stage' => 'awaiting_check', 'since' => (string)($row['received_on'] ?? $row['sent_on'])];
            }
            return null;
        }
        if ($kind === 'notice' && $row['status'] === 'active' && ($row['delivered_on'] ?? null) === null) {
            return ['stage' => 'in_transit', 'since' => $row['sent_on']];
        }
        return null;
    }

    public function build(User $user, mixed $condominiumId, mixed $asOf = null, mixed $days = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : $asOf;
        $date = is_string($asOf) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) : false;
        if ($date === false || $date->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $limit = $days === null || $days === '' ? self::DEFAULT_DAYS
            : (is_int($days) || is_string($days) ? filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]) : false);
        if ($limit === false) {
            throw new InvalidArgumentException('Prazo em dias: de 1 a 90.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $open = [];
        $stmt = $this->pdo->prepare("SELECT id, status, sent_on, received_on, checked_on, documents_count FROM erp_pouches
            WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('active', 'received') AND sent_on <= ?
            ORDER BY id LIMIT " . (self::MAX_OPEN + 1));
        $stmt->execute([(int)$id, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $open[] = ['pouch', $row];
        }
        $stmt = $this->pdo->prepare("SELECT id, status, sent_on, delivered_on, unit_id FROM erp_delivery_notices
            WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND sent_on <= ?
            ORDER BY id LIMIT " . (self::MAX_OPEN + 1));
        $stmt->execute([(int)$id, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $open[] = ['notice', $row];
        }
        if (count($open) > self::MAX_OPEN) {
            throw new InvalidArgumentException(sprintf('Mais de %d documentos em aberto: dê baixa pelos cadastros.', self::MAX_OPEN));
        }

        $summary = array_fill_keys(self::STAGES, ['open' => 0, 'overdue' => 0]);
        $items = [];
        foreach ($open as [$kind, $row]) {
            $row = array_map(static fn (mixed $v): mixed => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : $v, $row);
            $stage = self::stage($kind, $row);
            if ($stage === null) {
                continue;
            }
            $age = Agreements::daysLate($stage['since'], $asOf);
            $overdue = $age > $limit;
            $summary[$stage['stage']]['open']++;
            $summary[$stage['stage']]['overdue'] += $overdue ? 1 : 0;
            $items[] = ['kind' => $kind, 'id' => (int)$row['id'], 'stage' => $stage['stage'], 'sent_on' => (string)$row['sent_on'], 'since' => $stage['since'],
                'days' => $age, 'overdue' => $overdue,
                'documents_count' => $kind === 'pouch' ? (int)$row['documents_count'] : null,
                'unit_id' => $kind === 'notice' && $row['unit_id'] !== null ? (int)$row['unit_id'] : null];
        }
        usort($items, static fn (array $x, array $y): int => [-$x['days'], $x['kind'], $x['id']] <=> [-$y['days'], $y['kind'], $y['id']]);
        return ['condominium_id' => (int)$id, 'as_of' => $asOf, 'days' => (int)$limit, 'summary' => $summary,
            'overdue' => count(array_filter($items, static fn (array $i): bool => $i['overdue'])),
            'items' => array_slice($items, 0, self::MAX_ITEMS), 'truncated' => count($items) > self::MAX_ITEMS];
    }
}
