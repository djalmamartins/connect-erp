<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Cobertura dos contatos úteis do condomínio (ADR 0252), somente leitura. O cadastro de contatos úteis (AP-015) guarda os
 * telefones de emergência, encanador, eletricista, gás e demais serviços para os moradores e a portaria, do próprio
 * condomínio ou compartilhados com todos (sem condomínio). Ninguém confere, porém, se o condomínio tem um contato ativo para
 * cada serviço: a portaria descobre que falta o eletricista na hora da pane.
 *
 * Reusa o que já existe: os serviços do cadastro (RegistryService::definition: contacts) e a regra das listas do cadastro
 * (o condomínio vê os próprios e os compartilhados, só ativos e sem excluídos para a cobertura).
 *
 * Regra pura summarize(): uma linha por serviço, sempre os do cadastro, com own (ativos do condomínio), shared (ativos
 * compartilhados), inactive_own (inativos do condomínio), with_email (ativos com e-mail) e covered (own + shared > 0);
 * serviço desconhecido conta como "Outro". totals soma as linhas; covered_services conta os serviços cobertos e
 * missing_essential lista os serviços essenciais (ESSENTIAL) sem nenhum contato ativo.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * serviços e as contagens: nenhum nome, telefone, e-mail ou observação.
 */
final class ContactCoverage
{
    public const MAX_ROWS = 10000;
    public const ESSENTIAL = ['emergencia', 'encanador', 'eletricista', 'gas'];

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
     * @param list<array{service:string, shared:bool, status:string, has_email:bool}> $contacts
     */
    public static function summarize(array $contacts): array
    {
        $services = RegistryService::definition('contacts')['fields']['service']['options'];
        $blank = ['own' => 0, 'shared' => 0, 'inactive_own' => 0, 'with_email' => 0];
        $rows = [];
        foreach ($services as $service => $label) {
            $rows[$service] = ['service' => $service, 'service_label' => $label] + $blank + ['covered' => false];
        }
        foreach ($contacts as $c) {
            $service = isset($rows[$c['service']]) ? $c['service'] : 'outro';
            if ($c['status'] !== 'active') {
                if (!$c['shared']) {
                    $rows[$service]['inactive_own']++;
                }
                continue;
            }
            $rows[$service][$c['shared'] ? 'shared' : 'own']++;
            if ($c['has_email']) {
                $rows[$service]['with_email']++;
            }
        }
        $totals = $blank;
        $covered = 0;
        $missing = [];
        foreach ($rows as $service => $row) {
            $rows[$service]['covered'] = $row['own'] + $row['shared'] > 0;
            foreach (array_keys($blank) as $key) {
                $totals[$key] += $row[$key];
            }
            if ($rows[$service]['covered']) {
                $covered++;
            } elseif (in_array($service, self::ESSENTIAL, true)) {
                $missing[] = $service;
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'covered_services' => $covered, 'services' => count($rows), 'missing_essential' => $missing];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT service, condominium_id, status, email FROM erp_useful_contacts
            WHERE deleted_at IS NULL AND (condominium_id = ? OR condominium_id IS NULL) ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d contatos: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['service' => (string)$r['service'],
            'shared' => $r['condominium_id'] === null, 'status' => (string)$r['status'], 'has_email' => trim((string)$r['email']) !== ''], $rows));
    }
}
