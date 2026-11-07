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

    private function emailBody(NotificationMessage $message, User $recipient): string
    {
        $name = htmlspecialchars($recipient->first_name ?: $recipient->fullName(), ENT_QUOTES, "UTF-8");
        $title = htmlspecialchars($message->title, ENT_QUOTES, "UTF-8");
        $body = nl2br(htmlspecialchars($message->body, ENT_QUOTES, "UTF-8"));
        $link = $this->safeLink((string)$message->link);
        $button = $link ? '<p style="margin:28px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, "UTF-8") . '" style="display:inline-block;padding:12px 20px;border-radius:6px;background:#6E00B3;color:#fff;text-decoration:none;font-weight:700">Ver no MovesOS</a></p>' : '';
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f5f3f7;font-family:Arial,sans-serif;color:#242332"><table role="presentation" width="100%"><tr><td style="padding:32px 15px"><table role="presentation" width="100%" style="max-width:620px;margin:auto;background:#fff;border:1px solid #ebe6ee;border-radius:8px"><tr><td style="padding:25px 32px;border-bottom:3px solid #6E00B3"><strong style="font-size:20px;color:#6E00B3">MovesOS</strong><br><small style="color:#777">' . htmlspecialchars(CONF_SITE_NAME, ENT_QUOTES, "UTF-8") . '</small></td></tr><tr><td style="padding:34px 32px"><p style="margin-top:0">Olá, ' . $name . '.</p><h1 style="font-size:22px">' . $title . '</h1><div style="font-size:15px;line-height:1.65;color:#565363">' . $body . '</div>' . $button . '<p style="margin:30px 0 0;color:#8a8490;font-size:12px">Mensagem automática enviada pelo MovesOS.</p></td></tr></table></td></tr></table></body></html>';
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
