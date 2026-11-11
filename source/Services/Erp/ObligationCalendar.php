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
use Source\Services\OnboardingChecklist;

/**
 * Calendário de vencimentos do condomínio (ADR 0218), somente leitura: o que vence em 30, 60 ou 90 dias, e o que já
 * venceu, numa lista só. Hoje cada vencimento está numa tela: a apólice do seguro (AP-118), os contratos (AP-119), o
 * mandato do síndico e do conselho (AP-014) e os documentos do checklist de chegada (AVCB, laudos, ADR 0013). O painel
 * operacional só conta seguros e contratos em 30 dias; o mandato não aparece em lugar nenhum.
 *
 * Fontes (cada uma só entra se o usuário pode vê-la; o controller passa a lista, fail-closed):
 * - insurances: apólices vigentes, pelo fim da vigência;
 * - contracts: contratos vigentes com fim (prazo indeterminado fica de fora);
 * - board: mandatos ativos, pelo fim do mandato (só o cargo, nenhum nome);
 * - checklist: reusa OnboardingChecklist::items (mesma situação da tela do checklist): validade do item em dia
 *   (expires_at) e prazo do protocolo em aberto (due_date). Sem responsável nem observações.
 *
 * Regra pura bucket(): expired (antes da data de referência), 30, 60 ou 90 (dias até vencer); depois disso, fora. A
 * janela (days = 30, 60 ou 90) corta os futuros; vencidos sempre entram. Não grava e não envia nada: os avisos por e-mail
 * do checklist continuam no OnboardingAlerts.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404).
 */
final class ObligationCalendar
{
    public const SOURCES = ['insurances', 'contracts', 'board', 'checklist'];
    public const WINDOWS = [30, 60, 90];
    public const BUCKETS = ['expired', '30', '60', '90'];
    public const MAX_ITEMS = 1000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Dias de $asOf até $date (negativo = já venceu). */
    public static function daysUntil(string $date, string $asOf): int
    {
        $from = new DateTimeImmutable(substr($asOf, 0, 10));
        $to = new DateTimeImmutable(substr($date, 0, 10));
        return (int)$from->diff($to)->format('%r%a');
    }

    /** Faixa do vencimento (regra pura): expired, 30, 60, 90 ou null (mais longe). */
    public static function bucket(string $date, string $asOf): ?string
    {
        $days = self::daysUntil($date, $asOf);
        return match (true) {
            $days < 0 => 'expired',
            $days <= 30 => '30',
            $days <= 60 => '60',
            $days <= 90 => '90',
            default => null,
        };
    }

    /** @param list<string> $sources fontes que o usuário pode ver (o controller decide pelas permissões) */
    public function build(User $user, mixed $condominiumId, array $sources, mixed $asOf = null, mixed $days = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : $asOf;
        $date = is_string($asOf) ? DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) : false;
        if ($date === false || $date->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $window = $days === null || $days === '' ? 90 : (is_int($days) || is_string($days) ? filter_var($days, FILTER_VALIDATE_INT) : false);
        if (!in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('Janela: 30, 60 ou 90 dias.');
        }
        $sources = array_values(array_intersect(self::SOURCES, $sources));
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar
        $until = $date->modify("+{$window} days")->format('Y-m-d');

        $candidates = [];
        if (in_array('insurances', $sources, true)) {
            $stmt = $this->pdo->prepare("SELECT id, insurer, policy_number, ends_on FROM erp_insurances
                WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND ends_on <= ? ORDER BY ends_on, id");
            $stmt->execute([(int)$id, $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $candidates[] = ['source' => 'insurances', 'id' => (int)$r['id'], 'kind' => 'policy_end',
                    'label' => sprintf('Seguro %s, apólice %s', (string)$r['insurer'], (string)$r['policy_number']), 'date' => (string)$r['ends_on']];
            }
        }
        if (in_array('contracts', $sources, true)) {
            $stmt = $this->pdo->prepare("SELECT id, supplier_id, object, ends_on FROM erp_contracts
                WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND ends_on IS NOT NULL AND ends_on <= ? ORDER BY ends_on, id");
            $stmt->execute([(int)$id, $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $candidates[] = ['source' => 'contracts', 'id' => (int)$r['id'], 'kind' => 'contract_end', 'label' => mb_substr((string)$r['object'], 0, 120),
                    'date' => (string)$r['ends_on'], 'supplier_id' => (int)$r['supplier_id']];
            }
        }
        if (in_array('board', $sources, true)) {
            $roles = RegistryService::definition('board')['fields']['role']['options'];
            $stmt = $this->pdo->prepare("SELECT id, role, term_ends_on FROM erp_board_members
                WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND term_ends_on <= ? ORDER BY term_ends_on, id");
            $stmt->execute([(int)$id, $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $candidates[] = ['source' => 'board', 'id' => (int)$r['id'], 'kind' => 'term_end',
                    'label' => 'Mandato: ' . ($roles[(string)$r['role']] ?? (string)$r['role']), 'date' => (string)$r['term_ends_on']];
            }
        }
        if (in_array('checklist', $sources, true)) {
            foreach ((new OnboardingChecklist($this->pdo, $date))->items((int)$id) as $item) {
                if ($item['status'] === 'regular' && $item['expires_at'] !== null) {
                    $candidates[] = ['source' => 'checklist', 'id' => $item['id'], 'kind' => 'document_expiry', 'label' => $item['title'],
                        'date' => $item['expires_at'], 'category' => $item['category']];
                } elseif (in_array($item['status'], OnboardingChecklist::OPEN_STATUSES, true) && $item['due_date'] !== null) {
                    $candidates[] = ['source' => 'checklist', 'id' => $item['id'], 'kind' => 'protocol_deadline', 'label' => $item['title'],
                        'date' => $item['due_date'], 'category' => $item['category'], 'protocol' => $item['protocol']];
                }
            }
        }

        $summary = array_fill_keys(self::BUCKETS, 0);
        $items = [];
        foreach ($candidates as $c) {
            $c['date'] = substr($c['date'], 0, 10);
            $bucket = self::bucket($c['date'], $asOf);
            if ($bucket === null || ($bucket !== 'expired' && (int)$bucket > $window)) {
                continue;
            }
            $summary[$bucket]++;
            $items[] = $c + ['days' => self::daysUntil($c['date'], $asOf), 'bucket' => $bucket];
        }
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['source'], $a['id']] <=> [$b['date'], $b['source'], $b['id']]);
        return ['condominium_id' => (int)$id, 'as_of' => $asOf, 'days' => $window, 'sources' => $sources,
            'hidden_sources' => array_values(array_diff(self::SOURCES, $sources)), 'summary' => $summary,
            'items' => array_slice($items, 0, self::MAX_ITEMS), 'truncated' => count($items) > self::MAX_ITEMS];
    }
}
