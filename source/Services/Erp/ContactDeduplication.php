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
 * Inativação em lote dos contatos úteis duplicados do condomínio (ADR 0257). O cadastro de contatos úteis (AP-015) guarda
 * os contatos do condomínio e os compartilhados com todos (sem condomínio); a cobertura dos contatos (ADR 0252) conta os
 * ativos por serviço. Quando a administradora passa a compartilhar o telefone dos bombeiros ou do eletricista de
 * confiança, as cópias que cada condomínio cadastrou continuam ativas: a portaria vê o mesmo telefone duas ou três vezes,
 * e a cópia antiga deixa de ser corrigida quando o compartilhado muda. Hoje cada cópia é inativada à mão.
 *
 * Reusa o que já existe: cada cópia é regravada pelo RegistryService::save do cadastro "contacts" só com a situação
 * inativa (valem as regras e a auditoria update do formulário); o telefone é comparado já só com os dígitos, como o
 * formulário grava. Nada é apagado e nada é enviado; o contato que fica não muda.
 *
 * Regra pura duplicates(), sobre os contatos ativos que o condomínio vê (os próprios e os compartilhados):
 * - mesmo serviço e mesmo telefone (só os dígitos, sem o 55 do país) formam um grupo;
 * - no grupo com contato compartilhado, fica o compartilhado mais antigo e todos os próprios são cópias;
 * - no grupo só de próprios, fica o mais antigo (menor id) e os demais são cópias.
 * Só contatos próprios do condomínio são inativados; os compartilhados ficam como estão.
 *
 * - Entram: todas as cópias do condomínio, ou só os contact_ids informados. Regra pura problem(): contato não encontrado
 *   no condomínio (os compartilhados também, porque não são do condomínio), já inativo, ou que não é cópia de nenhum
 *   contato ativo.
 * - Tudo ou nada, numa transação com os contatos próprios travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _contact). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro deduplicate por contato (serviço e o contato que fica), só quando
 *   grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, serviço, o id do contato que
 * fica e se ele é compartilhado: nenhum nome, telefone, e-mail ou observação. Até MAX_CONTACTS por vez.
 */
final class ContactDeduplication
{
    public const MAX_CONTACTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Telefone para comparar: só os dígitos, sem o 55 do país (regra pura). */
    public static function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone) ?? '';
        return strlen($digits) >= 12 && str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    }

    /**
     * Cópias (regra pura): id do contato próprio => id do contato que fica.
     *
     * @param list<array{id:int, shared:bool, service:string, phone:?string}> $contacts ativos que o condomínio vê
     * @return array<int, int>
     */
    public static function duplicates(array $contacts): array
    {
        $groups = [];
        foreach ($contacts as $c) {
            $phone = self::phoneKey($c['phone']);
            if ($phone === '') {
                continue;
            }
            $groups[$c['service'] . '|' . $phone][] = $c;
        }
        $out = [];
        foreach ($groups as $group) {
            usort($group, static fn (array $a, array $b): int => [$a['shared'] ? 0 : 1, $a['id']] <=> [$b['shared'] ? 0 : 1, $b['id']]);
            $kept = array_shift($group);
            foreach ($group as $c) {
                if (!$c['shared']) {
                    $out[(int)$c['id']] = (int)$kept['id'];
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o contato pode ser inativado. */
    public static function problem(?array $row, ?int $keptId): ?string
    {
        return match (true) {
            $row === null => 'Contato não encontrado neste condomínio (os compartilhados só mudam no cadastro).',
            ($row['status'] ?? '') !== 'active' => 'O contato já está inativo.',
            $keptId === null => 'O contato não é cópia de nenhum contato ativo.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deduplicate(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $ids = null;
        if (array_key_exists('contact_ids', $input) && $input['contact_ids'] !== null) {
            if (!is_array($input['contact_ids']) || $input['contact_ids'] === []) {
                throw new InvalidArgumentException('Contatos: informe a lista de ids ou deixe vazio para todas as cópias.');
            }
            $ids = [];
            foreach ($input['contact_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Contatos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_CONTACTS) {
                throw new InvalidArgumentException(sprintf('Até %d contatos por vez.', self::MAX_CONTACTS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('contacts')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'contacts' => []];
        $this->pdo->beginTransaction();
        try {
            // Os próprios travados (os que podem mudar); os compartilhados só para comparar.
            $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_useful_contacts WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id FOR UPDATE");
            $stmt->execute([(int)$condominium]);
            $own = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $own[(int)$r['id']] = $r;
            }
            $shared = $this->pdo->query("SELECT id, service, phone FROM erp_useful_contacts WHERE condominium_id IS NULL AND deleted_at IS NULL AND status = 'active'")
                ->fetchAll(PDO::FETCH_ASSOC);
            $visible = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'shared' => true, 'service' => (string)$r['service'], 'phone' => $r['phone']], $shared);
            foreach ($own as $r) {
                if ($r['status'] === 'active') {
                    $visible[] = ['id' => (int)$r['id'], 'shared' => false, 'service' => (string)$r['service'], 'phone' => $r['phone']];
                }
            }
            $copies = self::duplicates($visible);
            $sharedIds = array_fill_keys(array_map(static fn (array $r): int => (int)$r['id'], $shared), true);
            if ($ids === null) {
                $chosen = array_keys($copies);
                if (count($chosen) > self::MAX_CONTACTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d cópias: inative em partes (escolha os ids).', self::MAX_CONTACTS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($own[$id] ?? null, $copies[$id] ?? null);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_contact' => (string)$id]);
                    }
                }
                $chosen = $ids;
            }
            foreach ($chosen as $id) {
                $row = $own[$id];
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $row[$field] === null ? null : (string)$row[$field];
                }
                try {
                    $registry->save($user, 'contacts', array_merge($data, ['status' => 'inactive']), (int)$id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_contact' => (string)$id]);
                }
                $result['contacts'][] = ['id' => (int)$id, 'service' => (string)$row['service'], 'kept_id' => $copies[$id], 'kept_shared' => isset($sharedIds[$copies[$id]])];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['contacts'] as $c) {
                Audit::record('deduplicate', 'erp_useful_contacts', $c['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'service' => $c['service'], 'kept_id' => $c['kept_id'], 'kept_shared' => $c['kept_shared'], 'batch' => count($result['contacts'])]);
            }
        }
        $result['count'] = count($result['contacts']);
        $result['kept_shared'] = count(array_filter($result['contacts'], static fn (array $c): bool => $c['kept_shared']));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
