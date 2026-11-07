<?php

namespace Source\Support;

use Source\Core\Connect;

final class Audit
{
    private const IGNORED_ENTITIES = [
        "notifications", "notification_messages", "notifications_categories", "system_audit_logs",
        "report_access", "report_online", "app_session", "app_log"
    ];

    public static function record(string $action, string $entity, $entityId = null, ?array $before = null, ?array $after = null): void
    {
        if (in_array($entity, self::IGNORED_ENTITIES, true)) {
            return;
        }

        try {
            $pdo = Connect::getInstance();
            $userId = isset($_SESSION["authUser"]) ? (int)$_SESSION["authUser"] : null;
            $context = self::changes($before ?? [], $after ?? []);
            $severity = $action === "delete" ? "warning" : "info";
            $labels = ["create" => "criou", "update" => "alterou", "delete" => "excluiu"];
            $description = sprintf("%s o registro %s #%s", $labels[$action] ?? $action, $entity, $entityId ?: "novo");
            $url = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?: null;
            $agent = mb_substr((string)($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255);
            $ip = filter_var($_SERVER["REMOTE_ADDR"] ?? null, FILTER_VALIDATE_IP) ?: null;

            $stmt = $pdo->prepare("INSERT INTO system_audit_logs (users_id, action, entity, entity_id, description, context_json, severity, ip, user_agent, url) VALUES (:user, :action, :entity, :entity_id, :description, :context, :severity, :ip, :agent, :url)");
            $stmt->execute(["user" => $userId, "action" => $action, "entity" => mb_substr($entity, 0, 100), "entity_id" => $entityId, "description" => $description, "context" => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "severity" => $severity, "ip" => $ip, "agent" => $agent, "url" => mb_substr((string)$url, 0, 255)]);

            $master = $pdo->query("SELECT id FROM users WHERE level >= 5 ORDER BY (level >= 10) DESC, level DESC, id ASC LIMIT 1")->fetch();
            if ($master && (int)$master->id !== $userId) {
                $category = $pdo->query("SELECT id FROM notifications_categories WHERE uri = 'studio' LIMIT 1")->fetch();
                if ($category) {
                    $notify = $pdo->prepare("INSERT INTO notifications (users_id, category, image, title, body, severity, link, view) VALUES (:user, :category, :image, :title, :body, :severity, :link, 0)");
                    $notify->execute(["user" => $master->id, "category" => $category->id, "image" => "images/default.svg", "title" => "Alteração no sistema", "body" => $description, "severity" => $severity, "link" => url("/studio/notifications")]);
                }
            }
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, 'audit', ['event_type' => 'audit_write_failed', 'action' => $action, 'entity' => $entity]);
            error_log("Audit error: " . $exception->getMessage());
        }
    }

    private static function changes(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (in_array($key, ["created_at", "updated_at"], true) || ($before[$key] ?? null) === ($after[$key] ?? null)) {
                continue;
            }
            $changed[$key] = ["before" => self::safeValue($key, $before[$key] ?? null), "after" => self::safeValue($key, $after[$key] ?? null)];
        }
        return ["changed_fields" => array_keys($changed), "changes" => $changed];
    }

    private static function safeValue(string $key, $value)
    {
        if (preg_match("~pass|password|token|csrf|secret|session|auth|document|email|phone|api.?key|credential|mail_user|pay_live|pay_test~i", $key)) {
            return "[REDACTED]";
        }
        if (is_scalar($value) || $value === null) {
            return mb_substr(preg_replace("~[\\r\\n\\t]+~", " ", strip_tags((string)$value)), 0, 500);
        }
        return "[COMPLEX_DATA]";
    }
}
