<?php

namespace Source\Support;

use Source\Core\Connect;
use Source\Models\Notification\Notification;
use Source\Models\Notification\NotificationCategory;
use Source\Models\Notification\NotificationMessage;
use Source\Models\User;

final class Communication
{
    private const ADMIN_LEVEL = 5;

    public function dispatchScheduled(int $limit = 50): int
    {
        $messages = (new NotificationMessage())->find("status = :status AND scheduled_at <= NOW()", "status=scheduled")->limit(max(1, min(200, $limit)))->fetch(true) ?: [];
        foreach ($messages as $message) {
            $this->deliver($message);
        }
        return count($messages);
    }

    public function deliver(NotificationMessage $message): void
    {
        $channels = $message->delivery_channels ?: "system";
        $sendSystem = in_array($channels, ["system", "both"], true);
        $sendEmail = in_array($channels, ["email", "both"], true);
        $category = $sendSystem ? $this->systemCategory() : null;

        foreach ($this->recipients($message) as $recipient) {
            if ($sendSystem && $category && !(new Notification())->find("message_id = :message AND users_id = :user", "message={$message->id}&user={$recipient->id}")->count()) {
                $notification = new Notification();
                $notification->message_id = $message->id;
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = "images/default.svg";
                $notification->title = $message->title;
                $notification->body = $message->body;
                $notification->severity = $message->severity;
                $notification->link = $message->link ?: url("/studio/dash");
                $notification->view = 0;
                $notification->expires_at = $message->expires_at;
                $notification->save();
            }
            if ($sendEmail && is_email((string)$recipient->email) && !$this->emailExists((int)$message->id, (int)$recipient->id)) {
                (new Email())->bootstrap($message->title, $this->emailBody($message, $recipient), $recipient->email, $recipient->fullName())
                    ->queue(CONF_MAIL_SENDER["address"], CONF_MAIL_SENDER["name"], date("Y-m-d H:i:s"), (int)$message->id, (int)$recipient->id);
            }
        }
        $message->status = "sent";
        $message->delivered_at = date("Y-m-d H:i:s");
        $message->save();
    }

    private function systemCategory(): ?NotificationCategory
    {
        $category = (new NotificationCategory())->findByUri("studio");
        if ($category) return $category;

        $category = new NotificationCategory();
        $category->title = "Studio";
        $category->uri = "studio";
        $category->description = "Notificações administrativas e alterações do sistema";
        $category->type = "system";
        return $category->save() ? $category : (new NotificationCategory())->findByUri("studio");
    }

    private function recipients(NotificationMessage $message): array
    {
        if ($message->audience === "admins") return (new User())->find("level >= :level", "level=" . self::ADMIN_LEVEL)->fetch(true) ?: [];
        if ($message->audience === "master") return (new User())->find("level >= :level", "level=" . self::ADMIN_LEVEL)->order("level DESC, id ASC")->limit(1)->fetch(true) ?: [];
        if ($message->audience === "user") {
            $target = (new User())->findById((int)$message->target_user_id);
            return $target ? [$target] : [];
        }
        return (new User())->find("status != :status", "status=trash")->fetch(true) ?: [];
    }

    private function emailExists(int $messageId, int $userId): bool
    {
        $stmt = Connect::getInstance()->prepare("SELECT COUNT(*) FROM mail_queue WHERE notification_message_id = :message AND users_id = :user");
        $stmt->execute(["message" => $messageId, "user" => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Corpo do e-mail na moldura Connect (ADR 0397); o texto é escapado pelo template. */
    private function emailBody(NotificationMessage $message, User $recipient): string
    {
        $link = $this->safeLink((string)$message->link);
        return (new MailTemplate())->render('notice', [
            'subject' => (string)$message->title,
            'preheader' => mb_strimwidth(trim((string)preg_replace('/\s+/', ' ', (string)$message->body)), 0, 120, '…'),
            'first_name' => (string)($recipient->first_name ?: $recipient->fullName()),
            'paragraphs' => [(string)$message->body],
            'button_label' => $link ? 'Ver no sistema' : '',
            'button_link' => (string)($link ?? ''),
        ]);
    }

    private function safeLink(string $link): ?string
    {
        if ($link === "") return null;
        if (str_starts_with($link, "/") && !str_starts_with($link, "//")) return url($link);
        $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($link, PHP_URL_HOST));
        $systemHost = strtolower((string)parse_url(url(), PHP_URL_HOST));
        return in_array($scheme, ["http", "https"], true) && $host && hash_equals($systemHost, $host) ? $link : null;
    }
}
