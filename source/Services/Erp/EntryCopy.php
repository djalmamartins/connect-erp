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

/**
 * Copiar lançamentos selecionados (revisão de 15/11 item 17, modelo 7, ADR 0313): cada lançamento escolhido na lista
 * vira um novo, na competência de destino, com os mesmos dados do cadastro (natureza, conta, centro de custo, descrição,
 * valor, unidade, fornecedor, documento e observações). O novo nasce em aberto (sem baixa nem conta bancária) e sem a
 * origem (fatura, acordo, cobrança extra): é um lançamento avulso. O vencimento anda os mesmos meses que a competência
 * (no último dia quando o mês é menor).
 *
 * Cada cópia passa pelo motor de cadastros (escopo, conta, unidade, fechamento mensal e auditoria). Tudo ou nada; um
 * condomínio só, do escopo; até BatchSettlement::MAX_ENTRIES lançamentos.
 */
final class EntryCopy
{
    /** Campos do cadastro que vão para a cópia (o resto nasce vazio). */
    public const FIELDS = ['condominium_id', 'nature', 'description', 'amount_cents', 'chart_account_id', 'cost_center_id', 'supplier_id', 'unit_id', 'document_number', 'notes'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Vencimento andando os mesmos meses que a competência (regra pura). */
    public static function dueOn(string $dueOn, string $from, string $to): string
    {
        $months = ((int)substr($to, 0, 4) - (int)substr($from, 0, 4)) * 12 + (int)substr($to, 5, 2) - (int)substr($from, 5, 2);
        $due = new \DateTimeImmutable($dueOn);
        $first = $due->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' month');
        return $first->format('Y-m-') . str_pad((string)min((int)$due->format('d'), (int)$first->format('t')), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{condominium_id:int, competence:string, count:int, total_cents:int, entries:list<array{from:int, id:int}>} */
    public function copy(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $competence = is_string($input['competence'] ?? null) ? trim($input['competence']) : '';
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Escolha a competência de destino (AAAA-MM).');
        }
        try {
            $ids = EntryCancellation::ids($input['entry_ids'] ?? null);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Escolha os lançamentos a copiar (até ' . BatchSettlement::MAX_ENTRIES . ').');
        }
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT id, competence, due_on, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", self::FIELDS))
            . " FROM erp_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) ORDER BY due_on, id");
        $stmt->execute(array_merge([(int)$condominium], $ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('Algum lançamento não existe ou é de outro condomínio.');
        }
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'competence' => $competence, 'count' => 0, 'total_cents' => 0, 'entries' => []];
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $data = [];
                foreach (self::FIELDS as $field) {
                    $data[$field] = $row[$field] === null ? '' : (string)$row[$field];
                }
                $cents = (int)$row['amount_cents'];
                $data['amount_cents'] = intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
                $data += ['competence' => $competence, 'due_on' => self::dueOn((string)$row['due_on'], (string)$row['competence'], $competence), 'status' => 'active'];
                try {
                    $saved = $registry->save($user, 'entries', $data);
                } catch (RegistryValidationException $exception) {
                    throw new RegistryValidationException($exception->errors + ['_entry' => 'Lançamento nº ' . (int)$row['id'] . ' (' . $row['description'] . ')']);
                }
                $result['entries'][] = ['from' => (int)$row['id'], 'id' => (int)$saved['id']];
                $result['count']++;
                $result['total_cents'] += $cents;
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $result;
    }
}
