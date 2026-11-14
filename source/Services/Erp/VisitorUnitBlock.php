<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Bloqueio em lote dos visitantes liberados de unidades sem morador ativo (ADR 0265). O cadastro de visitantes (AP-012)
 * guarda os visitantes frequentes e prestadores autorizados por unidade, muitos com acesso permanente (a diarista, o
 * personal, o namorado da filha). Quando a família se muda, a saída da unidade (ADR 0206) encerra os vínculos e pode
 * inativar veículos e animais, e o encerramento dos vínculos vencidos (ADR 0241) acerta os moradores; os visitantes,
 * porém, seguem "Liberado": a portaria continua deixando entrar quem foi autorizado por quem já não mora ali. O bloqueio
 * das autorizações vencidas (ADR 0235) só pega quem tem data vencida, e não quem tem acesso permanente.
 *
 * Reusa o que já existe: cada visitante é regravado pelo RegistryService::save do cadastro "visitors" só com a situação
 * "Bloqueado" (valem as regras e a auditoria update do formulário), no mesmo desenho do bloqueio das autorizações
 * vencidas (ADR 0235). Morador ativo é o da regra das listas do cadastro: vínculo ativo, sem excluído e sem fim de vínculo
 * passado. Nada vai ao morador nem ao visitante; para liberar de novo, o formulário.
 *
 * - Entram: os visitantes liberados do condomínio vinculados a uma unidade (unit_id) que não tem nenhum morador ativo,
 *   ou só os visitor_ids informados. Regra pura problem(): visitante não encontrado no condomínio, já bloqueado, sem
 *   unidade vinculada (só o texto da unidade: vincule no cadastro), cuja unidade ainda tem morador ativo, cuja unidade
 *   está inativa, ou sem data nem acesso permanente (o formulário não regrava: corrija no cadastro). Sem ids, os sem
 *   unidade vinculada ficam de fora e a contagem sai em skipped_unlinked; os de unidade inativa, em skipped_inactive_unit;
 *   os sem data, em skipped_incomplete.
 * - Tudo ou nada, numa transação com os visitantes travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _visitor). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro block_unattached por visitante (acesso permanente, data e motivo),
 *   só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, se o acesso era permanente e
 * a data "autorizado até": nenhum nome, documento, telefone, unidade ou observação. Até MAX_VISITORS por vez; motivo
 * obrigatório.
 */
final class VisitorUnitBlock
{
    public const MAX_VISITORS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o visitante pode ser bloqueado. */
    public static function problem(?array $row, bool $unitHasResident, bool $unitActive = true): ?string
    {
        return match (true) {
            $row === null => 'Visitante não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O visitante já está bloqueado.',
            ($row['unit_id'] ?? null) === null => 'O visitante não tem unidade vinculada (só o texto da unidade): vincule a unidade no cadastro.',
            $unitHasResident => 'A unidade do visitante ainda tem morador ativo.',
            !$unitActive => 'A unidade do visitante está inativa: o formulário não regrava. Corrija no cadastro.',
            empty($row['permanent_access']) && substr((string)($row['valid_until'] ?? ''), 0, 10) === ''
                => 'Visitante sem data nem acesso permanente: o formulário não regrava. Corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Unidades (das informadas) com morador ativo e unidades ativas do condomínio.
     *
     * @param list<int> $units
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function units(int $condominium, array $units): array
    {
        if ($units === []) {
            return [[], []];
        }
        $in = implode(',', array_fill(0, count($units), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT unit_id FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND (ends_on IS NULL OR ends_on >= ?) AND unit_id IN ({$in})");
        $stmt->execute(array_merge([$condominium, date('Y-m-d')], $units));
        $inhabited = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ({$in})");
        $stmt->execute(array_merge([$condominium], $units));
        $active = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        return [$inhabited, $active];
    }

    /** @param array<string, mixed> $input */
    public function block(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('visitor_ids', $input) && $input['visitor_ids'] !== null) {
            if (!is_array($input['visitor_ids']) || $input['visitor_ids'] === []) {
                throw new InvalidArgumentException('Visitantes: informe a lista de ids ou deixe vazio para todos os de unidades sem morador.');
            }
            $ids = [];
            foreach ($input['visitor_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Visitantes: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_VISITORS) {
                throw new InvalidArgumentException(sprintf('Até %d visitantes por vez.', self::MAX_VISITORS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('visitors')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_unlinked' => 0, 'skipped_inactive_unit' => 0,
            'skipped_incomplete' => 0, 'visitors' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    ORDER BY id FOR UPDATE");
                $stmt->execute([(int)$condominium]);
                $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $linked = array_values(array_filter($found, static fn (array $r): bool => $r['unit_id'] !== null));
                $result['skipped_unlinked'] = count($found) - count($linked);
                [$inhabited, $active] = $this->units((int)$condominium, array_values(array_unique(array_map(static fn (array $r): int => (int)$r['unit_id'], $linked))));
                $rows = [];
                foreach ($linked as $r) {
                    $unit = (int)$r['unit_id'];
                    if (isset($inhabited[$unit])) {
                        continue;
                    }
                    if (!isset($active[$unit])) {
                        $result['skipped_inactive_unit']++;
                    } elseif (self::problem($r, false, true) !== null) {
                        $result['skipped_incomplete']++;
                    } else {
                        $rows[] = $r;
                    }
                }
                if (count($rows) > self::MAX_VISITORS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d visitantes a bloquear: bloqueie em partes (escolha os ids).', self::MAX_VISITORS));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $units = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['unit_id'] ?? 0), $byId))));
                [$inhabited, $active] = $this->units((int)$condominium, $units);
                $rows = [];
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $unit = (int)($row['unit_id'] ?? 0);
                    $problem = self::problem($row, isset($inhabited[$unit]), isset($active[$unit]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_visitor' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'valid_until' => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'visitors', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_visitor' => (string)$row['id']]);
                }
                $result['visitors'][] = ['id' => (int)$row['id'], 'permanent_access' => !empty($row['permanent_access']),
                    'valid_until' => $row['valid_until'] === null ? null : substr((string)$row['valid_until'], 0, 10)];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['visitors'] as $v) {
                Audit::record('block_unattached', 'erp_visitors', $v['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'permanent_access' => $v['permanent_access'], 'valid_until' => $v['valid_until'], 'reason' => $reason,
                        'batch' => count($result['visitors'])]);
            }
        }
        $result['count'] = count($result['visitors']);
        $result['permanent'] = count(array_filter($result['visitors'], static fn (array $v): bool => $v['permanent_access']));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
