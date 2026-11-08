<?php

declare(strict_types=1);

namespace Source\Services;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;
use Source\Support\MailTemplate;

/**
 * Avisos diários do checklist de chegada (ADR 0013): item em dia que vence em até
 * 30 dias, item vencido e protocolo com prazo vencido. Cada item é avisado uma vez por
 * situação (alerted_situation); qualquer atualização do item zera o controle.
 *
 * Destinatários: o responsável do item; sem responsável, quem tem
 * erp.condominiums.manage e acesso ao condomínio. Aviso no sistema (notifications)
 * e e-mail na fila (mail_queue), enviado pelo worker de e-mail existente.
 */
final class OnboardingAlerts
{
    public const ALERT_SITUATIONS = ['expiring', 'expired', 'overdue'];

    private PDO $pdo;
    private DateTimeImmutable $today;

    public function __construct(?PDO $pdo = null, ?DateTimeImmutable $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /** @return array{items:int, notifications:int, emails:int} */
    public function run(): array
    {
        $result = ['items' => 0, 'notifications' => 0, 'emails' => 0];
        $limit = $this->today->modify('+' . OnboardingChecklist::EXPIRING_DAYS . ' days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT DISTINCT i.condominium_id FROM condominium_onboarding_items i
            JOIN condominiums c ON c.id = i.condominium_id AND c.status = 'active'
            WHERE (i.status = 'regular' AND i.expires_at IS NOT NULL AND i.expires_at <= :limit)
               OR (i.status IN ('action_required','in_progress') AND i.due_date IS NOT NULL AND i.due_date < :today)");
        $stmt->execute(['limit' => $limit, 'today' => $this->today->format('Y-m-d')]);
        $condominiums = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $checklist = new OnboardingChecklist($this->pdo, $this->today);
        $category = (int)$this->pdo->query("SELECT id FROM notifications_categories WHERE uri = 'studio' ORDER BY id LIMIT 1")->fetchColumn();
        foreach ($condominiums as $condominiumId) {
            $name = (string)$this->pdo->query('SELECT name FROM condominiums WHERE id = ' . $condominiumId)->fetchColumn();
            foreach ($checklist->items($condominiumId) as $item) {
                if (!in_array($item['situation'], self::ALERT_SITUATIONS, true) || $item['alerted_situation'] === $item['situation']) {
                    continue;
                }
                $recipients = $item['responsible'] !== null
                    ? $this->users([(int)$item['responsible']['id']])
                    : $this->managers($condominiumId);
                foreach ($recipients as $user) {
                    if ($category > 0) {
                        $this->notify($user, $category, $condominiumId, $name, $item);
                        $result['notifications']++;
                    }
                    if ($this->email($user, $condominiumId, $name, $item)) {
                        $result['emails']++;
                    }
                }
                $this->pdo->prepare('UPDATE condominium_onboarding_items SET alerted_situation = :situation, alerted_at = NOW() WHERE id = :id')
                    ->execute(['situation' => $item['situation'], 'id' => $item['id']]);
                $result['items']++;
            }
        }
        return $result;
    }

    /** Texto do aviso, em português simples. */
    public static function message(string $condominium, array $item): string
    {
        $date = static fn(?string $value): string => $value ? date('d/m/Y', strtotime($value)) : '';
        return match ($item['situation']) {
            'expiring' => "{$item['title']} do {$condominium} vence em {$date($item['expires_at'])}.",
            'expired' => "{$item['title']} do {$condominium} venceu em {$date($item['expires_at'])}.",
            'overdue' => "O prazo do protocolo {$item['protocol']} ({$item['title']}, {$condominium}) venceu em {$date($item['due_date'])}.",
            default => "{$item['title']} do {$condominium} precisa de atenção.",
        };
    }

    /** @param array<string, mixed> $item */
    private function notify(object $user, int $category, int $condominiumId, string $name, array $item): void
    {
        $title = $item['situation'] === 'expiring' ? 'Item do checklist vencendo' : 'Item do checklist vencido';
        $this->pdo->prepare("INSERT INTO notifications (users_id, category, image, title, body, severity, link, view, expires_at)
            VALUES (:user, :category, 'images/default.svg', :title, :body, :severity, :link, 0, DATE_ADD(NOW(), INTERVAL 1 MONTH))")
            ->execute([
                'user' => $user->id, 'category' => $category, 'title' => $title, 'body' => self::message($name, $item),
                'severity' => $item['situation'] === 'expiring' ? 'warning' : 'error', 'link' => $this->link($condominiumId, (int)$item['id']),
            ]);
    }

    /** @param array<string, mixed> $item */
    private function email(object $user, int $condominiumId, string $name, array $item): bool
    {
        if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            $html = (new MailTemplate())->render('checklist-alert', [
                'first_name' => (string)$user->first_name, 'message' => self::message($name, $item),
                'protocol' => $item['protocol'], 'link' => $this->link($condominiumId, (int)$item['id']),
            ]);
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, 'mail', ['event_type' => 'onboarding_alert_template_failed', 'condominium_id' => $condominiumId]);
            return false;
        }
        $subject = ($item['situation'] === 'expiring' ? 'Vence em breve: ' : 'Vencido: ') . $item['title'] . ' — ' . $name;
        $this->pdo->prepare("INSERT INTO mail_queue (users_id, subject, body, from_email, from_name, recipient_email, recipient_name, status, scheduled_at)
            VALUES (:user, :subject, :body, :from_email, :from_name, :email, :name, 'pending', NOW())")
            ->execute([
                'user' => $user->id, 'subject' => mb_substr($subject, 0, 255), 'body' => $html,
                'from_email' => CONF_MAIL_SENDER['address'], 'from_name' => CONF_MAIL_SENDER['name'],
                'email' => $user->email, 'name' => trim($user->first_name . ' ' . $user->last_name),
            ]);
        return true;
    }

    private function link(int $condominiumId, int $itemId): string
    {
        return url("/erp/condominios/{$condominiumId}/checklist#item-{$itemId}");
    }

    /** @param list<int> $ids @return list<object> */
    private function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id IN ({$placeholders}) AND status = 'confirmed'");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** Usuários cujo papel concede erp.condominiums.manage (sem negação individual) e que alcançam o condomínio. */
    private function managers(int $condominiumId): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT u.id FROM users u
            JOIN access_user_roles ur ON ur.user_id = u.id
            JOIN access_roles r ON r.id = ur.role_id
            JOIN access_permissions p ON p.slug = 'erp.condominiums.manage'
            LEFT JOIN access_user_overrides o ON o.user_id = u.id AND o.permission_id = p.id
            WHERE u.status = 'confirmed'
              AND (o.effect = 'allow' OR (COALESCE(o.effect, '') <> 'deny'
                   AND (r.slug = 'developer' OR EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id))))");
        $scope = new CondominiumScope($this->pdo);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $user = (new \Source\Models\User())->findById((int)$id);
            if ($user !== null && $scope->canAccess($user, $condominiumId)) {
                $ids[] = (int)$id;
            }
        }
        return $this->users($ids);
    }
}
