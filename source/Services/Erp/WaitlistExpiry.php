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
 * Baixa em lote das inscrições da lista de espera cuja data passou (ADR 0237). A lista de espera das áreas comuns
 * (AP-115) guarda quem quer uma área numa data já reservada; a inscrição nasce "Aguardando" (status active) e só sai daí
 * quando alguém marca "Atendida" ou "Desistiu". Quando a data desejada passa sem vaga, ela fica aguardando para sempre:
 * polui a lista, o painel operacional e a chamada automática da lista (OperationalDashboard). Hoje cada uma é baixada à
 * mão no formulário.
 *
 * Reusa o que já existe: cada inscrição é regravada pelo RegistryService::save do cadastro "waitlist" só com a situação
 * "Desistiu" (inactive, a saída sem atendimento do cadastro; valem as regras e a auditoria update do formulário), no
 * mesmo desenho da baixa das reservas pendentes (ADR 0221) e das visitas previstas (ADR 0231). O cadastro não envia aviso;
 * nada vai ao morador.
 *
 * - Entram: as inscrições aguardando do condomínio com data desejada anterior a before (padrão hoje; nunca depois de
 *   hoje), ou só as entry_ids informadas. Regra pura problem(): inscrição não encontrada no condomínio, que não está
 *   aguardando (atendida ou desistiu), cuja data ainda não passou, ou cuja área comum ou unidade ficou inativa (o
 *   formulário não regrava: corrija no cadastro). Sem ids, as de área ou unidade inativa ficam de fora e a contagem sai em
 *   skipped_inactive_link.
 * - Tudo ou nada, numa transação com as inscrições travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _entry). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro expire por inscrição (data desejada, se tinha sido chamada e motivo),
 *   só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, área comum, data desejada e se
 * tinha sido chamada: nenhum morador, unidade ou observação. Até MAX_ENTRIES por vez; motivo obrigatório.
 */
final class WaitlistExpiry
{
    public const MAX_ENTRIES = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a inscrição pode ser baixada. */
    public static function problem(?array $row, string $before, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Inscrição não encontrada neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'A inscrição não está aguardando (já foi atendida ou desistiu).',
            substr((string)($row['desired_on'] ?? ''), 0, 10) >= $before => 'A data desejada ainda não passou.',
            !$linksActive => 'A área comum ou a unidade da inscrição está inativa: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids das inscrições cuja área comum e unidade seguem ativas (o formulário só regrava essas).
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function activeLinks(int $condominium, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT w.id FROM erp_waitlist w
            JOIN erp_common_areas a ON a.id = w.common_area_id AND a.deleted_at IS NULL AND a.status = 'active'
            JOIN erp_units u ON u.id = w.unit_id AND u.deleted_at IS NULL AND u.status = 'active'
            WHERE w.condominium_id = ? AND w.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute(array_merge([$condominium], $ids));
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** @param array<string, mixed> $input */
    public function expire(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = date('Y-m-d');
        $before = $input['before'] ?? null;
        $before = $before === null || $before === '' ? $today : $before;
        $date = is_string($before) ? DateTimeImmutable::createFromFormat('!Y-m-d', $before) : false;
        if ($date === false || $date->format('Y-m-d') !== $before || $before > $today) {
            throw new InvalidArgumentException('Data de corte: AAAA-MM-DD, até hoje.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('entry_ids', $input) && $input['entry_ids'] !== null) {
            if (!is_array($input['entry_ids']) || $input['entry_ids'] === []) {
                throw new InvalidArgumentException('Inscrições: informe a lista de ids ou deixe vazio para todas as vencidas aguardando.');
            }
            $ids = [];
            foreach ($input['entry_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Inscrições: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_ENTRIES) {
                throw new InvalidArgumentException(sprintf('Até %d inscrições por vez.', self::MAX_ENTRIES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('waitlist')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, called_at, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'reason' => $reason, 'skipped_inactive_link' => 0,
            'entries' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_waitlist WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND desired_on < ? ORDER BY desired_on, id LIMIT " . (self::MAX_ENTRIES + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $before]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_ENTRIES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d inscrições vencidas: baixe em partes (data de corte menor).', self::MAX_ENTRIES));
                }
                // Área ou unidade inativa: o formulário não regrava; fica de fora e é contada.
                $active = $this->activeLinks((int)$condominium, array_map(static fn (array $r): int => (int)$r['id'], $rows));
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_waitlist WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $active = $this->activeLinks((int)$condominium, array_keys($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before, isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_entry' => (string)$id]);
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
                        $field === 'desired_on' => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'waitlist', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_entry' => (string)$row['id']]);
                }
                $result['entries'][] = ['id' => (int)$row['id'], 'common_area_id' => (int)$row['common_area_id'],
                    'desired_on' => substr((string)$row['desired_on'], 0, 10), 'called' => $row['called_at'] !== null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['entries'] as $e) {
                Audit::record('expire', 'erp_waitlist', $e['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'desired_on' => $e['desired_on'], 'common_area_id' => $e['common_area_id'], 'called' => $e['called'],
                        'reason' => $reason, 'batch' => count($result['entries'])]);
            }
        }
        $result['count'] = count($result['entries']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
