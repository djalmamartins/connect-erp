<?php

declare(strict_types=1);

namespace Source\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\ContractRenewal;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Renovação em lote dos itens do checklist de chegada (ADR 0219). O calendário de vencimentos (ADR 0218) e os avisos do
 * checklist (OnboardingAlerts, ADR 0013) apontam o que vence: extintores, SPDA, limpeza da caixa d'água, dedetização,
 * certidões, AVCB. Quando a empresa entrega vários laudos no mesmo dia, cada item é atualizado um a um na tela do
 * checklist, com a nova validade calculada à mão.
 *
 * Reusa o que já existe:
 * - OnboardingChecklist::update: cada item passa para "Em dia" com a nova validade, com a validação, o resolved_at, a
 *   limpeza do controle de avisos (alerted_situation) e a auditoria update do checklist;
 * - renewal_months do catálogo (onboarding_item_templates) e ContractRenewal::renewedEnd (mesma soma de meses com fim de
 *   mês, ADR 0217) para a validade padrão.
 *
 * - Regras puras: newExpiry() (validade informada ou data da renovação + meses do catálogo) e problem() (item não
 *   encontrado no condomínio; "não se aplica"; sem validade padrão no catálogo e sem validade informada; validade
 *   informada que não é depois da renovação).
 * - Tudo ou nada, numa transação com os itens travados (FOR UPDATE): um recusado desfaz todos (RegistryValidationException
 *   com _form ou os erros do checklist, e _item). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do checklist e um registro renew por item (situação e validade antes e depois, data da
 *   renovação), só quando grava. Nada é enviado.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, código, título, categoria,
 * situação e validade: nenhum responsável nem observação. Até MAX_ITEMS por vez.
 */
final class OnboardingRenewal
{
    public const MAX_ITEMS = 100;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Nova validade (regra pura): a informada, ou a data da renovação + os meses do catálogo; null se não há como saber. */
    public static function newExpiry(string $renewedOn, ?int $renewalMonths, ?string $explicit): ?string
    {
        if ($explicit !== null) {
            return $explicit;
        }
        return $renewalMonths !== null && $renewalMonths > 0 ? ContractRenewal::renewedEnd($renewedOn, $renewalMonths) : null;
    }

    /** Motivo da recusa antes do checklist (regra pura); null quando segue. */
    public static function problem(?array $row, string $renewedOn, ?string $explicit): ?string
    {
        return match (true) {
            $row === null => 'Item não encontrado no checklist deste condomínio.',
            ($row['status'] ?? '') === 'not_applicable' => 'Item marcado como "não se aplica": reabra na tela do checklist.',
            $explicit === null && ($row['renewal_months'] ?? null) === null => 'Item sem validade padrão no catálogo: informe a nova validade.',
            $explicit !== null && $explicit <= $renewedOn => 'A nova validade precisa ser depois da data da renovação.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function renew(User $user, mixed $condominiumId, array $input): array
    {
        $condominium = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $renewedOn = $input['renewed_on'] ?? null;
        $date = is_string($renewedOn) ? DateTimeImmutable::createFromFormat('!Y-m-d', $renewedOn) : false;
        if ($date === false || $date->format('Y-m-d') !== $renewedOn) {
            throw new InvalidArgumentException('Data da renovação: AAAA-MM-DD.');
        }
        if ($renewedOn > date('Y-m-d')) {
            throw new InvalidArgumentException('A data da renovação não pode ser futura.');
        }
        $explicit = $input['expires_at'] ?? null;
        if ($explicit === '') {
            $explicit = null;
        }
        if ($explicit !== null) {
            $check = is_string($explicit) ? DateTimeImmutable::createFromFormat('!Y-m-d', $explicit) : false;
            if ($check === false || $check->format('Y-m-d') !== $explicit) {
                throw new InvalidArgumentException('Nova validade: AAAA-MM-DD.');
            }
        }
        $raw = $input['item_ids'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os itens do checklist.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Itens: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf('Renove até %d itens por vez.', self::MAX_ITEMS));
        }
        $ids = array_values($ids);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $checklist = new OnboardingChecklist($this->pdo, $date);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'renewed_on' => $renewedOn, 'expires_at' => $explicit, 'items' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT i.id, i.status, i.expires_at, i.category, i.title, t.code, t.renewal_months
                FROM condominium_onboarding_items i LEFT JOIN onboarding_item_templates t ON t.id = i.template_id
                WHERE i.condominium_id = ? AND i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row, $renewedOn, $explicit);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_item' => (string)$id]);
                }
                $months = $row['renewal_months'] !== null ? (int)$row['renewal_months'] : null;
                $expiry = (string)self::newExpiry($renewedOn, $months, $explicit);
                try {
                    $checklist->update((int)$condominium, $id, ['status' => 'regular', 'expires_at' => $expiry], (int)$user->id);
                } catch (OnboardingValidationError $error) {
                    throw new RegistryValidationException($error->errors + ['_item' => (string)$id]);
                }
                $result['items'][] = ['id' => $id, 'code' => $row['code'] !== null ? (string)$row['code'] : null, 'title' => (string)$row['title'],
                    'category' => (string)$row['category'], 'status_before' => (string)$row['status'],
                    'expires_before' => $row['expires_at'] !== null ? substr((string)$row['expires_at'], 0, 10) : null, 'expires_after' => $expiry];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['items'] as $item) {
                Audit::record('renew', 'condominium_onboarding_items', $item['id'], ['status' => $item['status_before'], 'expires_at' => $item['expires_before']],
                    ['status' => 'regular', 'expires_at' => $item['expires_after'], 'renewed_on' => $renewedOn, 'batch' => count($ids)]);
            }
        }
        $result['count'] = count($result['items']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
