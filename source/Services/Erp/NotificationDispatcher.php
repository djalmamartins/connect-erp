<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\Email;
use Source\Support\MailTemplate;

/**
 * Fila de envio das notificações do ERP (ADR 0055). Consome a outbox (ADR 0054).
 *
 * Modo de entrega (regra de segurança):
 *  - log (padrão): só registra no log; nada sai.
 *  - sandbox: enfileira na mail_queue, sempre para a caixa de teste ERP_MAIL_SANDBOX_TO (mailpit ou
 *    equivalente do container send). Sem essa caixa no ambiente, cai para log.
 *  - production: só com APP_ENV=production E ERP_MAIL_DELIVERY=production no ambiente; então
 *    enfileira para o morador. A mail_queue é enviada pelo Email::sendQueue existente.
 * Fora de produção, nenhum e-mail sai para morador, qualquer que seja a configuração.
 *
 * Cada evento: modelo de container/send, destinatários pelo tipo do evento, trilha por destinatário
 * (e-mail em hash e mascarado), retentativas com espera crescente e no máximo MAX_ATTEMPTS.
 */
final class NotificationDispatcher
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Aviso por tipo de evento (ADR 0055 e 0056): modelo de container/send, assunto e público.
     * 'unit' = pessoas ativas da unidade com e-mail; 'billing' = só quem autorizou receber cobrança
     * por e-mail no cadastro (billing_by_email, o opt-in), responsável financeiro e proprietário primeiro.
     */
    public const NOTICES = [
        'waitlist.called' => ['template' => 'waitlist-called', 'subject' => 'Sua vez na lista de espera', 'audience' => 'unit'],
        'invoice.issued' => ['template' => 'invoice-issued', 'subject' => 'Nova cobrança do condomínio', 'audience' => 'billing'],
        'bank_charge.issued' => ['template' => 'bank-charge-issued', 'subject' => 'Seu boleto do condomínio', 'audience' => 'billing'],
        'agreement.created' => ['template' => 'agreement-created', 'subject' => 'Seu acordo e as parcelas', 'audience' => 'billing'],
        'agreement.breached' => ['template' => 'agreement-breached', 'subject' => 'Acordo encerrado por falta de pagamento', 'audience' => 'billing'],
        'reservation.approved' => ['template' => 'reservation-approved', 'subject' => 'Reserva aprovada', 'audience' => 'unit'],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    private string $mode;
    private ?string $sandboxTo;
    /** @var callable(string, string, string, string): ?int enfileira (assunto, corpo, e-mail, nome) e devolve o id da mail_queue */
    private $enqueue;

    public function __construct(?PDO $pdo = null, ?string $appEnv = null, ?string $configured = null, ?string $sandboxTo = null, ?callable $enqueue = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = new CondominiumScope($this->pdo);
        $this->sandboxTo = $sandboxTo ?? (getenv('ERP_MAIL_SANDBOX_TO') ?: null);
        $this->mode = self::deliveryMode($appEnv ?? (defined('CONF_APP_ENV') ? (string)CONF_APP_ENV : 'development'),
            $configured ?? (getenv('ERP_MAIL_DELIVERY') ?: 'log'), $this->sandboxTo);
        $this->enqueue = $enqueue ?? fn (string $subject, string $body, string $email, string $name): ?int => $this->queueMail($subject, $body, $email, $name);
    }

    /** Modo efetivo (regra pura): production só em produção e com configuração explícita; sandbox exige a caixa de teste. */
    public static function deliveryMode(string $appEnv, string $configured, ?string $sandboxTo): string
    {
        $configured = strtolower(trim($configured));
        if ($configured === 'production' && $appEnv === 'production') {
            return 'production';
        }
        if (($configured === 'sandbox' || $configured === 'production') && $sandboxTo !== null && filter_var($sandboxTo, FILTER_VALIDATE_EMAIL)) {
            return 'sandbox';
        }
        return 'log';
    }

    /** Espera antes da próxima tentativa (regra pura): 1, 5, 15, 60 e 240 minutos. */
    public static function backoffMinutes(int $attempts): int
    {
        return [1, 5, 15, 60, 240][max(0, min($attempts - 1, 4))];
    }

    /** E-mail mascarado para a trilha (regra pura): "ana@exemplo.com" → "a**@exemplo.com". */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('*', max(1, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return array{mode:string, processed:int, sent:int, failed:int, retry:int, cancelled:int} */
    public function dispatch(int $limit = 50): array
    {
        $summary = ['mode' => $this->mode, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0, 'cancelled' => 0];
        $ids = $this->pdo->query("SELECT id FROM erp_notification_outbox WHERE status = 'pending' AND available_at <= NOW()
            ORDER BY available_at, id LIMIT " . max(1, min($limit, 500)))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $claim = $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'processing', attempts = attempts + 1 WHERE id = :id AND status = 'pending'");
            $claim->execute(['id' => $id]);
            if ($claim->rowCount() === 0) {
                continue;   // outro processo pegou
            }
            $summary['processed']++;
            $summary[$this->deliver((int)$id)]++;
        }
        return $summary;
    }

    /** Reenvia um aviso que falhou ou foi cancelado (ação explícita, auditada). */
    public function retry(User $user, int $outboxId): array
    {
        $row = $this->row($user, $outboxId);
        if (!in_array($row['status'], ['failed', 'cancelled'], true)) {
            throw new DomainException('Só aviso com falha ou cancelado pode ser reenviado.');
        }
        $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'pending', attempts = 0, available_at = NOW(), last_error = NULL WHERE id = :id")
            ->execute(['id' => $outboxId]);
        Audit::record('update', 'erp_notification_outbox', $outboxId, ['status' => $row['status']], ['status' => 'pending']);
        return $this->find($user, $outboxId);
    }

    /** @return array{data:list<array>, total:int} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 'condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (in_array($filters['status'] ?? null, ['pending', 'processing', 'sent', 'failed', 'cancelled'], true)) {
            $where .= ' AND status = :s';
            $params['s'] = $filters['status'];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM erp_notification_outbox WHERE {$where} ORDER BY id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => count($rows)];
    }

    public function find(User $user, int $outboxId): array
    {
        $out = $this->present($this->row($user, $outboxId));
        $stmt = $this->pdo->prepare('SELECT delivery_mode, recipient_masked, sent_to, mail_queue_id, created_at FROM erp_notification_deliveries WHERE outbox_id = :id ORDER BY id');
        $stmt->execute(['id' => $outboxId]);
        $out['deliveries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $out;
    }

    private function deliver(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_notification_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        $payload = json_decode((string)$event['payload_json'], true) ?: [];
        try {
            $notice = self::NOTICES[$event['event_type']] ?? null;
            if ($notice === null) {
                return $this->finish($id, 'cancelled', 0, 'Tipo de aviso sem modelo de e-mail.');
            }
            $template = $notice['template'];
            $recipients = $this->recipients($event, $payload, $notice['audience']);
            if ($recipients === []) {
                return $this->finish($id, 'cancelled', 0, $notice['audience'] === 'billing'
                    ? 'Ninguém da unidade autorizou receber cobrança por e-mail.' : 'Nenhum destinatário com e-mail cadastrado.');
            }
            $context = $this->context($event, $payload);
            $mail = new MailTemplate();
            $count = 0;
            $done = $this->pdo->prepare('SELECT 1 FROM erp_notification_deliveries WHERE outbox_id = :o AND recipient_hash = :h');
            foreach ($recipients as $recipient) {
                $done->execute(['o' => $id, 'h' => hash('sha256', strtolower($recipient['email']))]);
                if ($done->fetchColumn()) {
                    $count++;   // já entregue numa tentativa anterior: não repete
                    continue;
                }
                $body = $mail->render($template, $context + ['first_name' => strtok($recipient['name'], ' ') ?: $recipient['name']]);
                $subject = $notice['subject'];
                $sentTo = null;
                $queueId = null;
                if ($this->mode === 'log') {
                    AppLogger::log('info', 'Aviso do ERP registrado em modo log (nada enviado)', ['event_type' => 'erp_notification_logged',
                        'outbox_id' => $id, 'recipient_hash' => hash('sha256', strtolower($recipient['email']))], 'mail');
                } else {
                    $sentTo = $this->mode === 'sandbox' ? (string)$this->sandboxTo : $recipient['email'];
                    $queueId = ($this->enqueue)($this->mode === 'sandbox' ? "[TESTE] {$subject}" : $subject, $body, $sentTo, $recipient['name']);
                    if ($queueId === null) {
                        throw new \RuntimeException('Não foi possível enfileirar o e-mail.');
                    }
                }
                $this->pdo->prepare('INSERT IGNORE INTO erp_notification_deliveries (outbox_id, delivery_mode, recipient_hash, recipient_masked, sent_to, mail_queue_id)
                    VALUES (:o, :m, :h, :mask, :to, :q)')->execute(['o' => $id, 'm' => $this->mode, 'h' => hash('sha256', strtolower($recipient['email'])),
                    'mask' => self::mask($recipient['email']), 'to' => $sentTo, 'q' => $queueId]);
                $count++;
            }
            return $this->finish($id, 'sent', $count, null);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'mail', ['event_type' => 'erp_notification_failed', 'outbox_id' => $id]);
            $attempts = (int)$event['attempts'];
            if ($attempts >= self::MAX_ATTEMPTS) {
                return $this->finish($id, 'failed', 0, mb_substr($error->getMessage(), 0, 255));
            }
            $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'pending', last_error = :e, available_at = DATE_ADD(NOW(), INTERVAL :m MINUTE) WHERE id = :id")
                ->execute(['e' => mb_substr($error->getMessage(), 0, 255), 'm' => self::backoffMinutes($attempts), 'id' => $id]);
            return 'retry';
        }
    }

    private function finish(int $id, string $status, int $count, ?string $error): string
    {
        $this->pdo->prepare('UPDATE erp_notification_outbox SET status = :s, delivery_mode = :m, recipients_count = :c, last_error = :e, processed_at = NOW() WHERE id = :id')
            ->execute(['s' => $status, 'm' => $status === 'sent' ? $this->mode : null, 'c' => $count, 'e' => $error, 'id' => $id]);
        Audit::record('update', 'erp_notification_outbox', $id, ['status' => 'processing'], ['status' => $status, 'delivery_mode' => $this->mode, 'recipients' => $count]);
        return $status;
    }

    /** @return list<array{email:string, name:string}> */
    private function recipients(array $event, array $payload, string $audience): array
    {
        if (empty($payload['unit_id'])) {
            return [];
        }
        $sql = $audience === 'billing'
            ? "SELECT name, email FROM erp_residents WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL
                AND status = 'active' AND billing_by_email = 1 AND email IS NOT NULL AND email <> ''
                ORDER BY relationship = 'financial' DESC, relationship = 'owner' DESC, id LIMIT 5"
            : "SELECT name, email FROM erp_residents WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL
                AND status = 'active' AND email IS NOT NULL AND email <> '' ORDER BY relationship = 'owner' DESC, id LIMIT 5";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['u' => (int)$payload['unit_id'], 'c' => (int)$event['condominium_id']]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $out[strtolower((string)$row['email'])] = ['email' => (string)$row['email'], 'name' => (string)$row['name']];
            }
        }
        return array_values($out);
    }

    /**
     * Variáveis do modelo a partir do payload (regra pura): "x_cents" vira "x" em reais
     * ("R$ 1.234,56"), data AAAA-MM-DD vira DD/MM/AAAA, competência AAAA-MM vira MM/AAAA; o resto
     * vai como texto. Ids (unit_id, ..._id) não entram no modelo.
     */
    public static function formatPayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || $key === 'id' || str_ends_with($key, '_id')) {
                continue;
            }
            if (str_ends_with($key, '_cents') && is_numeric($value)) {
                $out[substr($key, 0, -6)] = 'R$ ' . number_format(((int)$value) / 100, 2, ',', '.');
                continue;
            }
            $text = (string)($value ?? '');
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
                $text = "{$m[3]}/{$m[2]}/{$m[1]}";
            } elseif ($key === 'competence' && preg_match('/^(\d{4})-(\d{2})$/', $text, $m)) {
                $text = "{$m[2]}/{$m[1]}";
            }
            $out[$key] = $text;
        }
        return $out;
    }

    private function context(array $event, array $payload): array
    {
        $condo = $this->pdo->prepare('SELECT name FROM condominiums WHERE id = :id');
        $condo->execute(['id' => (int)$event['condominium_id']]);
        $context = self::formatPayload($payload) + ['condominium' => (string)($condo->fetchColumn() ?: 'condomínio')];
        if (isset($payload['common_area_id'])) {
            $area = $this->pdo->prepare('SELECT name FROM erp_common_areas WHERE id = :id AND condominium_id = :c');
            $area->execute(['id' => (int)$payload['common_area_id'], 'c' => (int)$event['condominium_id']]);
            $context['area'] = (string)($area->fetchColumn() ?: 'área comum');
        }
        return $context;
    }

    private function queueMail(string $subject, string $body, string $email, string $name): ?int
    {
        $mail = (new Email())->bootstrap($subject, $body, $email, $name);
        if (!$mail->queue()) {
            return null;
        }
        return (int)Connect::getInstance()->lastInsertId() ?: null;
    }

    private function row(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_notification_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'event_type' => (string)$row['event_type'],
            'status' => (string)$row['status'], 'delivery_mode' => $row['delivery_mode'] ?? null, 'recipients_count' => (int)($row['recipients_count'] ?? 0),
            'attempts' => (int)$row['attempts'], 'available_at' => $row['available_at'], 'processed_at' => $row['processed_at'],
            'last_error' => $row['last_error'], 'created_at' => $row['created_at'],
        ];
    }
}
