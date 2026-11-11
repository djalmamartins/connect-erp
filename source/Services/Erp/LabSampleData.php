<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use RuntimeException;
use Source\Support\Audit;

/**
 * Dados de exemplo para testar a inadimplência no laboratório (ADR 0162). Só roda fora de produção:
 * guard() exige ERP_DEMO_DATA ligado, ambiente lab ou dev E nome de banco com marca de laboratório; em
 * dúvida, recusa.
 *
 * Cria, com a marca "EXEMPLO" no começo de cada nome:
 * - dois condomínios (o residencial com 6 unidades, sem CNPJ; o comercial com 2 salas);
 * - proprietários (um deles com unidades nos dois condomínios) e um inquilino com as duas salas;
 * - contas a receber: 3 unidades vencidas há 30, 60 e 120 dias, uma em dia, uma paga e uma sem conta.
 *   A de 120 dias é a candidata a acordo. Nada de boleto, banco real ou e-mail: só lançamentos.
 * Idempotente: o que já existe com a marca é reaproveitado. remove() apaga só o que tem a marca, dentro
 * dos condomínios marcados, numa transação.
 */
final class LabSampleData
{
    public const MARK = 'EXEMPLO';
    public const ENVIRONMENTS = ['lab', 'dev', 'development', 'local'];
    /** O nome do banco precisa ter uma destas marcas (a produção não tem nenhuma). */
    public const DATABASE_MARKS = ['lab', 'dev', 'local', 'sandbox'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Trava (regra pura): null = pode rodar; texto = motivo da recusa. Ambiente vazio, desconhecido ou de
     * produção recusa; banco sem marca de laboratório também, a menos que o nome seja exatamente o de
     * ERP_DEMO_DB (o lab do Djalma usa connect_legacy). Nome com "prod" recusa sempre.
     */
    public static function guard(?string $environment, ?string $database, mixed $demoData, ?string $allowedDatabase = null): ?string
    {
        $environment = strtolower(trim((string)$environment));
        $database = strtolower(trim((string)$database));
        // ERP_DEMO_DATA precisa estar ligado de forma explícita (true, 1, on, yes); ausente ou ambíguo recusa.
        if ($demoData !== true && filter_var(is_scalar($demoData) ? (string)$demoData : '', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== true) {
            return 'Recusado: ERP_DEMO_DATA precisa estar ligado (true) para criar ou remover dados de exemplo.';
        }
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            return 'Recusado: o ambiente precisa ser lab ou dev (MOVESOS_ENV ou APP_ENV), e não "' . ($environment === '' ? 'vazio' : $environment) . '".';
        }
        if ($database === '' || str_contains($database, 'prod')) {
            return 'Recusado: o banco não parece de laboratório.';
        }
        $allowedDatabase = strtolower(trim((string)$allowedDatabase));
        if ($allowedDatabase !== '' && hash_equals($allowedDatabase, $database)) {
            return null;   // liberado pelo nome exato em ERP_DEMO_DB
        }
        foreach (self::DATABASE_MARKS as $mark) {
            if (str_contains($database, $mark)) {
                return null;
            }
        }
        return 'Recusado: o nome do banco precisa ter lab, dev, local ou sandbox, ou ser exatamente o de ERP_DEMO_DB.';
    }

    /** CPF de exemplo com dígitos verificadores válidos, a partir de 9 dígitos (regra pura). */
    public static function cpf(string $base): string
    {
        $digits = substr(str_pad((string)preg_replace('/\D/', '', $base), 9, '0'), 0, 9);
        foreach ([10, 11] as $weight) {
            $sum = 0;
            for ($i = 0; $i < $weight - 1; $i++) {
                $sum += (int)$digits[$i] * ($weight - $i);
            }
            $rest = ($sum * 10) % 11;
            $digits .= (string)($rest === 10 ? 0 : $rest);
        }
        return $digits;
    }

    /**
     * O que vai ser criado (regra pura), em datas relativas a $today.
     *
     * @return array{condominiums:list<array>, people:list<array>, entries:list<array>}
     */
    public static function plan(string $today): array
    {
        $day = static fn (int $offset): string => (new \DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
        $m = self::MARK;
        return [
            'condominiums' => [
                ['key' => 'res', 'name' => "{$m} Residencial Aurora", 'city' => 'São Paulo', 'state' => 'SP',
                    'units' => [['101', 'apartamento'], ['102', 'apartamento'], ['103', 'apartamento'], ['104', 'apartamento'], ['105', 'apartamento'], ['106', 'apartamento']]],
                ['key' => 'com', 'name' => "{$m} Comercial Centro", 'city' => 'São Paulo', 'state' => 'SP',
                    'units' => [['Sala 1', 'sala'], ['Sala 2', 'sala']]],
            ],
            'people' => [
                ['name' => "{$m} Ana Proprietária", 'document' => self::cpf('111444777'), 'role' => 'owner', 'units' => [['res', '101'], ['com', 'Sala 1']]],
                ['name' => "{$m} Bruno Proprietário", 'document' => self::cpf('222555888'), 'role' => 'owner', 'units' => [['res', '102'], ['res', '103']]],
                ['name' => "{$m} Carla Proprietária", 'document' => self::cpf('333666999'), 'role' => 'owner', 'units' => [['res', '104'], ['res', '105'], ['res', '106'], ['com', 'Sala 2']]],
                ['name' => "{$m} Davi Inquilino", 'document' => self::cpf('444777000'), 'role' => 'tenant', 'units' => [['com', 'Sala 1'], ['com', 'Sala 2']]],
            ],
            'entries' => [
                ['unit' => ['res', '101'], 'due_on' => $day(-30), 'paid_on' => null, 'amount_cents' => 65000, 'label' => 'vencida há 30 dias'],
                ['unit' => ['res', '102'], 'due_on' => $day(-60), 'paid_on' => null, 'amount_cents' => 65000, 'label' => 'vencida há 60 dias'],
                ['unit' => ['res', '103'], 'due_on' => $day(-120), 'paid_on' => null, 'amount_cents' => 65000, 'label' => 'vencida há 120 dias (candidata a acordo)'],
                ['unit' => ['res', '104'], 'due_on' => $day(10), 'paid_on' => null, 'amount_cents' => 65000, 'label' => 'em dia (vence em 10 dias)'],
                ['unit' => ['res', '105'], 'due_on' => $day(-20), 'paid_on' => $day(-18), 'amount_cents' => 65000, 'label' => 'paga'],
            ],
        ];
    }

    /** @return array<string, int> quantos registros foram criados por tipo */
    public function create(?string $today = null): array
    {
        $plan = self::plan($today ?? date('Y-m-d'));
        $created = ['condominiums' => 0, 'units' => 0, 'residents' => 0, 'entries' => 0];
        $this->pdo->beginTransaction();
        try {
            $condos = [];
            $units = [];
            foreach ($plan['condominiums'] as $condo) {
                $id = $this->id('SELECT id FROM condominiums WHERE name = ?', [$condo['name']]);
                if ($id === null) {
                    $this->pdo->prepare("INSERT INTO condominiums (name, legal_name, document, status, city, state) VALUES (?, ?, NULL, 'active', ?, ?)")
                        ->execute([$condo['name'], $condo['name'], $condo['city'], $condo['state']]);
                    $id = (int)$this->pdo->lastInsertId();
                    $created['condominiums']++;
                }
                $condos[$condo['key']] = $id;
                foreach ($condo['units'] as [$number, $type]) {
                    $unit = $this->id('SELECT id FROM erp_units WHERE condominium_id = ? AND number = ? AND deleted_at IS NULL', [$id, $number]);
                    if ($unit === null) {
                        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, number, type, notes) VALUES (?, ?, ?, ?)')
                            ->execute([$id, $number, $type, self::MARK . ': dados de exemplo do laboratório']);
                        $unit = (int)$this->pdo->lastInsertId();
                        $created['units']++;
                    }
                    $units[$condo['key'] . '|' . $number] = $unit;
                }
            }
            foreach ($plan['people'] as $person) {
                foreach ($person['units'] as [$key, $number]) {
                    $unit = $units[$key . '|' . $number];
                    if ($this->id('SELECT id FROM erp_residents WHERE unit_id = ? AND relationship = ? AND document = ? AND deleted_at IS NULL', [$unit, $person['role'], $person['document']]) === null) {
                        $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$condos[$key], $unit, $number, $person['role'], $person['name'], $person['document']]);
                        $created['residents']++;
                    }
                }
            }
            $chart = $this->chart($condos['res']);
            $bank = $this->bank($condos['res']);
            foreach ($plan['entries'] as $entry) {
                [$key, $number] = $entry['unit'];
                $description = self::MARK . " Taxa condominial {$number} ({$entry['label']})";
                if ($this->id('SELECT id FROM erp_entries WHERE condominium_id = ? AND description = ? AND deleted_at IS NULL', [$condos[$key], $description]) === null) {
                    $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, unit_id, description, amount_cents, competence, due_on, paid_on, status)
                        VALUES (?, 'receita', ?, ?, ?, ?, ?, ?, ?, ?, 'active')")
                        ->execute([$condos[$key], $chart, $entry['paid_on'] !== null ? $bank : null, $units[$key . '|' . $number], $description,
                            $entry['amount_cents'], substr($entry['due_on'], 0, 7), $entry['due_on'], $entry['paid_on']]);
                    $created['entries']++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw new RuntimeException('Dados de exemplo não criados: ' . $error->getMessage(), 0, $error);
        }
        return $created;
    }

    /**
     * Apaga só o que tem a marca, dentro dos condomínios marcados. $before (ADR 0175) roda antes, na mesma
     * transação: é por onde o ano contábil de exemplo (LabSampleYear::purge) sai primeiro. Se qualquer passo
     * falhar ou recusar, nada é apagado.
     *
     * @param null|callable(): array<string, int> $before
     * @return array<string, int>
     */
    public function remove(?callable $before = null): array
    {
        $like = self::MARK . ' %';
        $ids = array_map('intval', $this->column('SELECT id FROM condominiums WHERE name LIKE ?', [$like]));
        $removed = ['entries' => 0, 'residents' => 0, 'units' => 0, 'bank_accounts' => 0, 'chart_accounts' => 0, 'condominiums' => 0];
        if ($ids === []) {
            return $removed;
        }
        $in = implode(',', $ids);
        $this->pdo->beginTransaction();
        try {
            $removed = ($before === null ? [] : $before()) + $removed;
            $removed['entries'] = $this->pdo->exec("DELETE FROM erp_entries WHERE condominium_id IN ({$in}) AND description LIKE " . $this->pdo->quote($like));
            $removed['residents'] = $this->pdo->exec("DELETE FROM erp_residents WHERE condominium_id IN ({$in}) AND name LIKE " . $this->pdo->quote($like));
            $removed['units'] = $this->pdo->exec("DELETE FROM erp_units WHERE condominium_id IN ({$in}) AND notes LIKE " . $this->pdo->quote(self::MARK . ':%'));
            $removed['bank_accounts'] = $this->pdo->exec("DELETE FROM erp_bank_accounts WHERE condominium_id IN ({$in}) AND name LIKE " . $this->pdo->quote($like));
            $removed['chart_accounts'] = $this->pdo->exec("DELETE FROM erp_chart_accounts WHERE condominium_id IN ({$in}) AND name LIKE " . $this->pdo->quote($like));
            $removed['condominiums'] = $this->pdo->exec("DELETE FROM condominiums WHERE id IN ({$in}) AND name LIKE " . $this->pdo->quote($like));
            // Trilha do que saiu (ADR 0175): contagem por tipo e os condomínios de exemplo apagados.
            Audit::record('delete', 'lab_sample_data', null, ['condominium_ids' => $ids] + $removed, null);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw new RuntimeException('Nada foi removido: algum registro de exemplo ainda é usado por outro cadastro (' . $error->getMessage() . ').', 0, $error);
        }
        return $removed;
    }

    private function chart(int $condominium): int
    {
        $name = self::MARK . ' Taxa condominial';
        $id = $this->id('SELECT id FROM erp_chart_accounts WHERE condominium_id = ? AND name = ? AND deleted_at IS NULL', [$condominium, $name]);
        if ($id === null) {
            $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '9.99', ?, 'receita', 'analitica')")->execute([$condominium, $name]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return $id;
    }

    private function bank(int $condominium): int
    {
        $name = self::MARK . ' Conta movimento';
        $id = $this->id('SELECT id FROM erp_bank_accounts WHERE condominium_id = ? AND name = ?', [$condominium, $name]);
        if ($id === null) {
            $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind) VALUES (?, ?, '000', '0000', '00000-0', 'corrente')")
                ->execute([$condominium, $name]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return $id;
    }

    private function id(string $sql, array $params): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int)$value;
    }

    /** @return list<mixed> */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
