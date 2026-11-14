<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Envios com falha dos comunicados publicados de volta à fila, em lote (ADR 0279). A situação dos comunicados (ADR 0278)
 * aponta em failed_ids os comunicados com envio com falha na outbox (ADR 0054). Reenviar hoje é abrir cada evento da
 * fila (ADR 0055) e pedir o reenvio, um a um; um comunicado para todas as unidades tem um evento por unidade.
 *
 * Reusa o que já existe: cada evento volta à fila pelo NotificationDispatcher::retry, o mesmo reenvio auditado da fila
 * (status pending, attempts zerado, available_at agora e last_error vazio). A situação do comunicado vem da
 * AnnouncementBoard::state (publicado, agendado ou cancelado). Nada é enviado aqui: o envio continua com o worker da fila
 * e com as travas de ambiente de sempre (fora de produção, só log ou caixa de teste). Nenhum e-mail é criado pelo lote.
 *
 * - Entram: os comunicados publicados (ativos e já saídos) do condomínio com algum evento com falha, ou só os
 *   announcement_ids informados. Regra pura problem(): não encontrado no condomínio, cancelado, agendado ou sem envio
 *   com falha. Sem ids, só entram os publicados com falha (nada a recusar).
 * - Tudo ou nada, numa transação com os comunicados e os eventos com falha travados (FOR UPDATE): um recusado desfaz
 *   todos (RegistryValidationException com _form e _announcement). dry_run passa por tudo e desfaz (inclusive a
 *   auditoria do reenvio de cada evento, que vai na mesma transação).
 * - Auditoria: a do NotificationDispatcher::retry por evento e um registro retry_failed por comunicado (eventos, motivo
 *   e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo e contagens: nenhum
 * assunto, texto, unidade, destinatário ou e-mail. Até MAX_ANNOUNCEMENTS por vez; motivo obrigatório.
 */
final class AnnouncementRetry
{
    public const MAX_ANNOUNCEMENTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;
    private NotificationDispatcher $dispatcher;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?NotificationDispatcher $dispatcher = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher($this->pdo);
    }

    /** Motivo da recusa (regra pura); null quando os envios com falha do comunicado podem voltar à fila. */
    public static function problem(?array $row, ?string $state, int $failed): ?string
    {
        return match (true) {
            $row === null || $state === null => 'Comunicado não encontrado neste condomínio.',
            $state === 'cancelled' => 'O comunicado foi cancelado: os envios dele não voltam à fila.',
            $state === 'scheduled' => 'O comunicado ainda está agendado: os envios saem na data marcada.',
            $failed < 1 => 'O comunicado não tem envio com falha.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function retry(User $user, array $input, ?\DateTimeImmutable $now = null): array
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
        if (array_key_exists('announcement_ids', $input) && $input['announcement_ids'] !== null) {
            if (!is_array($input['announcement_ids']) || $input['announcement_ids'] === []) {
                throw new InvalidArgumentException('Comunicados: informe a lista de ids ou deixe vazio para todos os publicados com falha.');
            }
            $ids = [];
            foreach ($input['announcement_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Comunicados: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_ANNOUNCEMENTS) {
                throw new InvalidArgumentException(sprintf('Até %d comunicados por vez.', self::MAX_ANNOUNCEMENTS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $now ??= new \DateTimeImmutable();
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'announcements' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, kind, status, send_at FROM erp_announcements WHERE condominium_id = ? ORDER BY id FOR UPDATE');
            $stmt->execute([(int)$condominium]);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'state' => AnnouncementBoard::state((string)$r['status'], (string)$r['send_at'], $now)];
            }
            // Os eventos com falha dos comunicados deste condomínio (o escopo vem do comunicado), travados.
            $stmt = $this->pdo->prepare("SELECT o.id, o.subject_id FROM erp_notification_outbox o JOIN erp_announcements a ON a.id = o.subject_id
                WHERE o.subject_table = 'erp_announcements' AND a.condominium_id = ? AND o.status = 'failed' ORDER BY o.id FOR UPDATE");
            $stmt->execute([(int)$condominium]);
            $failed = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $failed[(int)$r['subject_id']][] = (int)$r['id'];
            }
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $row) {
                    if ($row['state'] === 'published' && isset($failed[$id])) {
                        $rows[] = $row;
                    }
                }
                if (count($rows) > self::MAX_ANNOUNCEMENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d comunicados com falha: reenvie em partes (escolha os ids).', self::MAX_ANNOUNCEMENTS));
                }
            } else {
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $problem = self::problem($row, $row['state'] ?? null, count($failed[$id] ?? []));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_announcement' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                foreach ($failed[$row['id']] as $event) {
                    $this->dispatcher->retry($user, $event);
                }
                $result['announcements'][] = ['id' => $row['id'], 'kind' => $row['kind'], 'events' => count($failed[$row['id']])];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['announcements'] as $a) {
                Audit::record('retry_failed', 'erp_announcements', $a['id'], null, ['events' => $a['events'], 'reason' => $reason,
                    'batch' => count($result['announcements'])]);
            }
        }
        $result['count'] = count($result['announcements']);
        $result['events'] = array_sum(array_column($result['announcements'], 'events'));
        $result['changed'] = $dryRun ? 0 : $result['events'];
        return $result;
    }
}
