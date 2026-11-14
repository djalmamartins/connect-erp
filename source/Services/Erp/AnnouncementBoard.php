<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Situação dos comunicados do condomínio (ADR 0278), somente leitura. Os comunicados gerais e os avisos de cobrança
 * (ADR 0059, AP-111, AP-137 e AP-138) ficam em erp_announcements e saem pela outbox (ADR 0054). A lista mostra até 200,
 * um a um; ninguém vê, de uma vez, quantos já saíram, quantos estão agendados, quantos foram cancelados, há quanto tempo
 * saíram e quantos tiveram envio com falha.
 *
 * O cadastro não tem data de fim nem arquivamento: o comunicado sai numa data (send_at) e, depois, só pode ser
 * cancelado. Por isso não há "vencidos" nem "sem data de fim" aqui; o cancelado é o que sai do ar. Não há migration.
 *
 * Reusa o que já existe: os tipos e os estados do próprio cadastro (KINDS e os estados active e cancelled, como na
 * Announcements::list) e os estados da outbox (DELIVERY, os da tabela erp_notification_outbox), contados pelos eventos
 * com subject_table erp_announcements, como na Announcements::stats.
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo, sempre todos os de KINDS, com announcements, published (ativo e já saiu), scheduled (ativo
 *   e ainda vai sair) e cancelled, que somam announcements; totals soma as linhas;
 * - by_age: idade dos publicados (dias desde o envio) em AGES, somando totals.published;
 * - delivery: eventos da outbox dos publicados por estado de DELIVERY, e failed_ids (comunicados com algum envio com
 *   falha, até MAX_IDS, com truncated);
 * - scheduled_ids: os agendados (até MAX_IDS, com truncated), do mais próximo ao mais distante.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, contagens, ids e a data de referência: nenhum assunto, texto, unidade, destinatário ou e-mail.
 */
final class AnnouncementBoard
{
    public const MAX_ROWS = 100000;
    public const MAX_IDS = 200;
    /** Tipos do cadastro (a enum kind de erp_announcements). */
    public const KINDS = ['general' => 'Comunicado', 'collection' => 'Aviso de cobrança'];
    /** Estados de um evento da outbox (a enum status de erp_notification_outbox). */
    public const DELIVERY = ['pending', 'processing', 'sent', 'failed', 'cancelled'];
    /** Idade dos publicados, em dias desde o envio: faixa => até quantos dias (null = sem limite). */
    public const AGES = ['ate_30' => 30, 'de_31_a_90' => 90, 'mais_de_90' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de idade (regra pura) para quantos dias se passaram desde o envio. */
    public static function age(int $days): string
    {
        foreach (self::AGES as $age => $limit) {
            if ($limit === null || $days <= $limit) {
                return $age;
            }
        }
        return (string)array_key_last(self::AGES);
    }

    /**
     * Situação de um comunicado (regra pura): published (ativo e já saiu), scheduled (ativo e ainda vai sair), cancelled,
     * ou null para um estado desconhecido. Também usada pelo reenvio em lote (ADR 0279).
     */
    public static function state(string $status, string $sendAt, \DateTimeImmutable $now): ?string
    {
        return match (true) {
            $status === 'cancelled' => 'cancelled',
            $status !== 'active' => null,
            new \DateTimeImmutable($sendAt) > $now => 'scheduled',
            default => 'published',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string, send_at:string}> $announcements
     * @param array<int, array<string, int>> $events id do comunicado => estado da outbox => eventos
     */
    public static function summarize(array $announcements, array $events, \DateTimeImmutable $now): array
    {
        $zero = ['announcements' => 0, 'published' => 0, 'scheduled' => 0, 'cancelled' => 0];
        $counts = array_fill_keys(array_keys(self::KINDS), $zero);
        $ages = array_fill_keys(array_keys(self::AGES), 0);
        $delivery = array_fill_keys(self::DELIVERY, 0);
        $failed = [];
        $scheduled = [];
        $today = $now->setTime(0, 0);
        foreach ($announcements as $a) {
            if (!isset($counts[$a['kind']])) {
                continue;
            }
            $sendAt = new \DateTimeImmutable($a['send_at']);
            $state = self::state($a['status'], $a['send_at'], $now);
            if ($state === null) {
                continue;
            }
            $counts[$a['kind']]['announcements']++;
            $counts[$a['kind']][$state]++;
            if ($state === 'scheduled') {
                $scheduled[] = [$a['send_at'], $a['id']];
            } elseif ($state === 'published') {
                $ages[self::age((int)$sendAt->setTime(0, 0)->diff($today)->days)]++;
                foreach ($events[$a['id']] ?? [] as $status => $n) {
                    if (isset($delivery[$status]) && $n > 0) {
                        $delivery[$status] += $n;
                        if ($status === 'failed') {
                            $failed[$a['id']] = true;
                        }
                    }
                }
            }
        }
        $rows = [];
        $totals = $zero;
        foreach (self::KINDS as $kind => $label) {
            $rows[] = ['kind' => $kind, 'kind_label' => $label] + $counts[$kind];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$kind][$key];
            }
        }
        sort($scheduled);
        $scheduledIds = array_column($scheduled, 1);
        $failedIds = array_keys($failed);
        sort($failedIds);
        return ['on' => $now->format('Y-m-d H:i:s'), 'rows' => $rows, 'totals' => $totals, 'by_age' => $ages,
            'delivery' => $delivery + ['failed_ids' => array_slice($failedIds, 0, self::MAX_IDS), 'failed_truncated' => count($failedIds) > self::MAX_IDS],
            'scheduled_ids' => array_slice($scheduledIds, 0, self::MAX_IDS), 'scheduled_truncated' => count($scheduledIds) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, ?\DateTimeImmutable $now = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, kind, status, send_at FROM erp_announcements WHERE condominium_id = ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d comunicados: fale com o suporte.', self::MAX_ROWS));
        }
        // Os eventos da outbox dos comunicados deste condomínio (o escopo vem do comunicado).
        $stmt = $this->pdo->prepare("SELECT o.subject_id, o.status, COUNT(*) AS n FROM erp_notification_outbox o JOIN erp_announcements a ON a.id = o.subject_id
            WHERE o.subject_table = 'erp_announcements' AND a.condominium_id = ? GROUP BY o.subject_id, o.status");
        $stmt->execute([(int)$id]);
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[(int)$r['subject_id']][(string)$r['status']] = (int)$r['n'];
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'],
            'status' => (string)$r['status'], 'send_at' => (string)$r['send_at']], $rows), $events, $now ?? new \DateTimeImmutable());
    }
}
