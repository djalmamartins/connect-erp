<?php

namespace Source\Support;

use PDO;
use Throwable;

final class AppLogger
{
    private static bool $booted = false;
    private static bool $writing = false;
    private static float $startedAt = 0.0;
    private static string $requestId = '';
    private static ?PDO $pdo = null;
    private static ?string $lastIncident = null;

    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$startedAt = microtime(true);
        self::$requestId = self::identifier(12);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            self::log(self::phpLevel($severity), $message, [
                'php_severity' => $severity,
                'file' => $file,
                'line' => $line,
                'event_type' => 'php_error',
                'code' => self::phpName($severity)
            ], 'php');
            return false;
        });

        set_exception_handler(static function (Throwable $exception): void {
            $incident = self::exception($exception, 'application', ['event_type' => 'uncaught_exception']);
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            $safeIncident = htmlspecialchars((string)$incident, ENT_QUOTES, 'UTF-8');
            echo "<!doctype html><html lang=\"pt-BR\"><meta charset=\"utf-8\"><title>Erro interno</title><body style=\"font-family:Arial,sans-serif;padding:8vw;color:#2f2138\"><h1>Não foi possível concluir esta ação.</h1><p>O erro foi registrado para análise.</p><p><strong>Código do incidente:</strong> {$safeIncident}</p></body></html>";
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::log('critical', (string)$error['message'], [
                    'php_severity' => $error['type'],
                    'file' => $error['file'] ?? null,
                    'line' => $error['line'] ?? null,
                    'event_type' => 'fatal_error',
                    'code' => self::phpName((int)$error['type'])
                ], 'php');
            }
            $elapsed = microtime(true) - self::$startedAt;
            if ($elapsed >= 5.0) {
                self::log('warning', 'Requisição lenta detectada', [
                    'duration_ms' => (int)round($elapsed * 1000),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                    'event_type' => 'slow_request',
                    'code' => 'SLOW_REQUEST'
                ], 'performance');
            }
            // Fora da produção, processa uma pequena parte da fila ao fim de cada requisição web, para os e-mails
            // saírem no laboratório sem o cron. Na produção o cron envia a fila a cada minuto (ADR 0358) e a página
            // não espera o SMTP: com poucos processos PHP, um envio lento prendia quem estava navegando (ADR 0611).
            if (PHP_SAPI !== 'cli' && class_exists(Email::class) && self::sendsMailInline()) {
                try {
                    (new Email())->sendQueue(5, 5);
                } catch (Throwable $mailFailure) {
                    self::fallback('warning', 'Não foi possível processar a fila de e-mail', [
                        'mail_failure' => $mailFailure->getMessage()
                    ], 'mail');
                }
            }
        });
    }

    /**
     * A fila de e-mail sai no fim da página só quando nenhum cron a envia: na produção (ERP_MAIL_DELIVERY=production)
     * o tools/hosting/cron.sh roda o service/jobs/sendEmail.php a cada minuto (ADR 0358 e 0611).
     */
    public static function sendsMailInline(?string $delivery = null): bool
    {
        $delivery ??= (string)(getenv('ERP_MAIL_DELIVERY') ?: (defined('ERP_MAIL_DELIVERY') ? constant('ERP_MAIL_DELIVERY') : ''));
        return strtolower(trim($delivery)) !== 'production';
    }

    public static function exception(Throwable $exception, string $channel = 'application', array $context = []): ?string
    {
        return self::log('error', $exception->getMessage(), array_merge($context, [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code' => (string)$exception->getCode(),
            'event_type' => $context['event_type'] ?? 'exception'
        ]), $channel);
    }

    public static function log(string $level, string $message, array $context = [], string $channel = 'application'): ?string
    {
        if (self::$writing) {
            self::fallback($level, $message, $context, $channel);
            return self::$lastIncident;
        }
        self::$writing = true;
        $incident = self::identifier(10);
        self::$lastIncident = $incident;

        try {
            $cleanMessage = self::cleanText($message, 8000);
            $file = isset($context['file']) ? self::cleanPath((string)$context['file']) : null;
            $line = isset($context['line']) ? (int)$context['line'] : null;
            $exceptionClass = isset($context['exception_class']) ? self::cleanText((string)$context['exception_class'], 255) : null;
            $trace = isset($context['trace']) ? self::cleanText((string)$context['trace'], 65000) : null;
            $eventType = self::cleanText((string)($context['event_type'] ?? 'application_event'), 100);
            $code = self::cleanText((string)($context['code'] ?? ''), 100) ?: null;
            unset($context['file'], $context['line'], $context['exception_class'], $context['trace'], $context['event_type'], $context['code']);
            $url = self::cleanText((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), 500);
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
            $fingerprint = hash('sha256', implode('|', [$level, $channel, $eventType, $exceptionClass, $code, $cleanMessage, $file, $line, $method, $url]));
            $userId = isset($context['users_id']) ? (int)$context['users_id'] : (isset($_SESSION['authUser']) ? (int)$_SESSION['authUser'] : null);
            $corporationId = isset($context['corporations_id']) ? (int)$context['corporations_id'] : (isset($_SESSION['authCorporation']) ? (int)$_SESSION['authCorporation'] : null);
            $condominiumId = isset($context['condominium_id']) ? (int)$context['condominium_id'] : (isset($_SESSION['authCondo']) ? (int)$_SESSION['authCondo'] : null);
            $status = isset($context['status']) && in_array($context['status'], ['open','resolved','ignored'], true) ? $context['status'] : (in_array(self::normalizeLevel($level), ['debug','info','notice'], true) ? 'resolved' : 'open');
            unset($context['users_id'], $context['corporations_id'], $context['condominium_id'], $context['status']);
            $safeContext = self::sanitize($context);
            $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null;
            $agent = self::cleanText((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'), 500);
            $pdo = self::pdo();

            // O mesmo erro ainda aberto soma ocorrências no incidente do último dia, em vez de abrir
            // um registro novo a cada 5 minutos e encher a página de logs (ADR 0258).
            $recent = $pdo->prepare("SELECT id,incident_id FROM app_log WHERE fingerprint=:fingerprint AND status='open' AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1");
            $recent->execute(['fingerprint' => $fingerprint]);
            $existing = $recent->fetch(PDO::FETCH_OBJ);
            if ($existing) {
                $update = $pdo->prepare("UPDATE app_log SET occurrences=occurrences+1,last_seen_at=NOW(),updated_at=NOW(),request_id=:request,context_json=:context,trace=COALESCE(:trace,trace) WHERE id=:id");
                $update->execute(['request' => self::$requestId, 'context' => json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'trace' => $trace, 'id' => $existing->id]);
                self::$lastIncident = (string)$existing->incident_id;
            } else {
                $insert = $pdo->prepare("INSERT INTO app_log (incident_id,request_id,corporations_id,condominium_id,users_id,level,channel,event_type,code,ip,msg,exception_class,file,line,url,context_json,trace,user_agent,fingerprint,occurrences,status,first_seen_at,last_seen_at) VALUES (:incident,:request,:corporation,:condominium,:user,:level,:channel,:event_type,:code,:ip,:message,:exception_class,:file,:line,:url,:context,:trace,:agent,:fingerprint,1,:status,NOW(),NOW())");
                $insert->execute([
                    'incident' => $incident, 'request' => self::$requestId,
                    'corporation' => $corporationId ?: null,
                    'condominium' => $condominiumId ?: null,
                    'user' => $userId ?: null, 'level' => self::normalizeLevel($level), 'channel' => self::cleanText($channel, 80),
                    'event_type' => $eventType, 'code' => $code, 'ip' => $ip, 'message' => $cleanMessage,
                    'exception_class' => $exceptionClass, 'file' => $file, 'line' => $line, 'url' => $url,
                    'context' => json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'trace' => $trace, 'agent' => $agent, 'fingerprint' => $fingerprint, 'status' => $status
                ]);
            }
            // O incidente fica registrado em Studio › Logs do sistema; não vira aviso no sininho nem
            // e-mail "Erro detectado no MovesOS" (ADR 0645). Quem acompanha os erros olha os logs.
            if (random_int(1, 100) === 1) {
                $pdo->exec("DELETE FROM app_log WHERE (status IN ('resolved','ignored') AND last_seen_at < DATE_SUB(NOW(), INTERVAL 90 DAY)) OR last_seen_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
            }
        } catch (Throwable $failure) {
            self::fallback($level, $message, array_merge($context, ['logger_failure' => $failure->getMessage(), 'incident_id' => $incident]), $channel);
        } finally {
            self::$writing = false;
        }
        return self::$lastIncident;
    }

    private static function pdo(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = new PDO('mysql:host=' . CONF_DB_HOST . ';port=' . CONF_DB_PORT . ';dbname=' . CONF_DB_NAME . ';charset=utf8mb4', CONF_DB_USER, CONF_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        return self::$pdo;
    }

    private static function fallback(string $level, string $message, array $context, string $channel): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $record = ['time' => date(DATE_ATOM), 'request_id' => self::$requestId, 'level' => $level, 'channel' => $channel, 'message' => self::cleanText($message, 8000), 'context' => self::sanitize($context)];
        @file_put_contents($directory . '/movesos-' . date('Y-m-d') . '.log', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        error_log('[MovesOS][' . strtoupper($level) . '] ' . $record['message']);
    }

    private static function sanitize($value, ?string $key = null, int $depth = 0)
    {
        if ($key && preg_match('~pass|password|passwd|token|csrf|secret|session|cookie|authorization|api.?key|database|dsn|mail_pass|pay_live|pay_test~i', $key)) {
            return '[REDACTED]';
        }
        if ($depth > 4) {
            return '[MAX_DEPTH]';
        }
        if (is_array($value) || is_object($value)) {
            $clean = [];
            foreach ((array)$value as $itemKey => $itemValue) {
                $clean[(string)$itemKey] = self::sanitize($itemValue, (string)$itemKey, $depth + 1);
            }
            return $clean;
        }
        if (is_string($value)) {
            return self::cleanText($value, 2000);
        }
        return is_scalar($value) || $value === null ? $value : '[UNSUPPORTED]';
    }

    private static function cleanText(string $value, int $limit): string
    {
        $value = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+~u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $limit);
    }

    private static function cleanPath(string $path): string
    {
        $root = dirname(__DIR__, 2);
        return self::cleanText(str_replace($root, '[PROJECT]', $path), 500);
    }

    private static function identifier(int $bytes): string
    {
        try {
            return strtoupper(bin2hex(random_bytes($bytes)));
        } catch (Throwable $exception) {
            return strtoupper(substr(hash('sha256', uniqid('', true)), 0, $bytes * 2));
        }
    }

    private static function normalizeLevel(string $level): string
    {
        return in_array($level, ['debug','info','notice','warning','error','critical','alert','emergency'], true) ? $level : 'error';
    }

    private static function phpLevel(int $severity): string
    {
        return in_array($severity, [E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR,E_PARSE], true) ? 'critical' : (in_array($severity, [E_WARNING,E_CORE_WARNING,E_COMPILE_WARNING,E_USER_WARNING], true) ? 'warning' : 'notice');
    }

    private static function phpName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE', E_STRICT => 'E_STRICT', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED', default => 'PHP_' . $severity
        };
    }
}
