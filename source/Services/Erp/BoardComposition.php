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

/**
 * Composição do síndico e do conselho do condomínio numa data (ADR 0254), somente leitura. O cadastro "Síndico e conselho"
 * (AP-014) guarda o cargo e o mandato de cada membro; o calendário de obrigações lista os fins de mandato e o encerramento
 * em lote (ADR 0243) inativa os vencidos. Ninguém responde, porém, como está a composição hoje: se há síndico com mandato
 * valendo e até quando, quais cargos estão vagos, quantos mandatos terminam nos próximos 30 ou 90 dias, quantos seguem
 * ativos no cadastro já vencidos, quantos foram eleitos e ainda não começaram, e quantos estão sem e-mail ou telefone
 * (o canal com o conselho, obrigatório desde a ADR 0151). A administradora usa isso para preparar a assembleia de eleição.
 *
 * Reusa o que já existe: os cargos do cadastro (RegistryService::definition('board')) e a regra das listas (sem excluídos).
 *
 * Regra pura summarize(), uma linha por cargo do cadastro, sempre todos:
 * - in_force: ativos com o mandato valendo na data on (início e fim inclusive);
 *   ending_0_30 e ending_31_90: dos in_force, os que terminam em até 30 dias ou de 31 a 90 dias;
 * - expired: ativos com o fim do mandato antes de on (a acertar pelo ADR 0243);
 * - not_started: ativos com o início depois de on (eleitos para o próximo mandato);
 * - inactive: inativos no cadastro;
 * - incomplete: ativos sem e-mail ou sem telefone.
 * Cargo desconhecido conta como "Suplente". totals soma as linhas. Fora das linhas: sindico_in_force,
 * sindico_term_ends_on (o fim mais distante entre os síndicos em vigor; null sem síndico) e vacant_roles (cargos sem
 * ninguém em vigor).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * cargos, as contagens e a data do fim do mandato do síndico: nenhum nome, documento, contato, unidade ou observação.
 */
final class BoardComposition
{
    public const MAX_ROWS = 10000;
    public const FALLBACK_ROLE = 'suplente';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{role:string, status:string, starts_on:string, ends_on:string, complete:bool}> $members
     */
    public static function summarize(array $members, string $on): array
    {
        $keys = ['in_force', 'ending_0_30', 'ending_31_90', 'expired', 'not_started', 'inactive', 'incomplete'];
        $rows = [];
        foreach (RegistryService::definition('board')['fields']['role']['options'] as $role => $label) {
            $rows[$role] = ['role' => $role, 'role_label' => $label] + array_fill_keys($keys, 0);
        }
        $sindicoUntil = null;
        $day = new DateTimeImmutable($on);
        foreach ($members as $m) {
            $role = isset($rows[$m['role']]) ? $m['role'] : self::FALLBACK_ROLE;
            if ($m['status'] !== 'active') {
                $rows[$role]['inactive']++;
                continue;
            }
            if (!$m['complete']) {
                $rows[$role]['incomplete']++;
            }
            $starts = substr($m['starts_on'], 0, 10);
            $ends = substr($m['ends_on'], 0, 10);
            if ($ends < $on) {
                $rows[$role]['expired']++;
                continue;
            }
            if ($starts > $on) {
                $rows[$role]['not_started']++;
                continue;
            }
            $rows[$role]['in_force']++;
            $days = (int)$day->diff(new DateTimeImmutable($ends))->days;
            if ($days <= 30) {
                $rows[$role]['ending_0_30']++;
            } elseif ($days <= 90) {
                $rows[$role]['ending_31_90']++;
            }
            if ($role === 'sindico' && ($sindicoUntil === null || $ends > $sindicoUntil)) {
                $sindicoUntil = $ends;
            }
        }
        $totals = array_fill_keys($keys, 0);
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] += $row[$key];
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'sindico_in_force' => $rows['sindico']['in_force'] > 0,
            'sindico_term_ends_on' => $sindicoUntil,
            'vacant_roles' => array_values(array_map(static fn (array $r): string => $r['role'], array_filter($rows, static fn (array $r): bool => $r['in_force'] === 0)))];
    }

    public function build(User $user, mixed $condominiumId, mixed $on = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $on === null || $on === '' ? date('Y-m-d') : $on;
        $date = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT role, status, term_starts_on, term_ends_on,
                CASE WHEN COALESCE(email, '') <> '' AND COALESCE(phone, '') <> '' THEN 1 ELSE 0 END AS complete
            FROM erp_board_members WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d membros: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(array_map(static fn (array $r): array => ['role' => (string)$r['role'],
            'status' => (string)$r['status'], 'starts_on' => substr((string)$r['term_starts_on'], 0, 10), 'ends_on' => substr((string)$r['term_ends_on'], 0, 10),
            'complete' => (int)$r['complete'] === 1], $rows), $on);
    }
}
