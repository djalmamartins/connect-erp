<?php
declare(strict_types=1);

namespace Source\Services\Talk;

use Source\Services\Talk\Erp\LocalContactDirectory;
use Source\Services\Talk\Erp\LocalHelpdesk;
use Source\Services\Talk\Gateway\GatewayFactory;
use Source\Services\Talk\Http\CurlHttpClient;
use Source\Services\Talk\Jack\Classifier;
use Source\Services\Talk\Jack\Jack;
use Source\Services\Talk\Media\MediaStore;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Realtime\SseStream;
use Source\Services\Talk\Support\Crypto;
use Source\Services\Talk\Support\Db;
use Source\Support\AppLogger;

/**
 * Montagem do Connect Talk dentro do ERP (ADR 0323). É o bootstrap.php do Talk separado, com o banco, o cadastro e o
 * Help Desk do próprio ERP.
 *
 * - Banco: o mesmo do ERP (CONF_DB_*), numa conexão própria, porque o Talk usa FETCH_ASSOC e prepares nativos.
 * - Cadastro e chamados: LocalContactDirectory e LocalHelpdesk (TalkHelpdesk), sem API nem token.
 * - Configuração: chaves TALK_* e EVOLUTION_* do .env do ERP (lista em source/Boot/Config.php). Uma chave sem
 *   prefixo pedida pelo código do Talk (ex.: JACK_MODE) é lida como TALK_JACK_MODE.
 */
final class TalkApp
{
    /** Lê a configuração do Talk. */
    public static function env(string $key, ?string $default = null): ?string
    {
        $name = str_starts_with($key, 'TALK_') || str_starts_with($key, 'EVOLUTION_') ? $key : 'TALK_' . $key;
        $value = getenv($name);
        return $value !== false && $value !== '' ? (string) $value : $default;
    }

    public static function db(): Db
    {
        $db = Db::connect(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', CONF_DB_HOST, (int) CONF_DB_PORT, CONF_DB_NAME),
            (string) CONF_DB_USER,
            (string) CONF_DB_PASS
        );
        $db->run("SET time_zone = '" . date('P') . "'");
        return $db;
    }

    /** @return array<string, mixed> */
    public static function build(?Db $db = null): array
    {
        $env = static fn (string $k, ?string $d = null): ?string => self::env($k, $d);
        $db ??= self::db();
        $log = static function (string $message): void {
            AppLogger::log('warning', 'Connect Talk: ' . $message, ['event_type' => 'talk_erp', 'origin' => 'talk'], 'integration');
        };
        $key = (string) $env('TALK_KEY', '');
        $crypto = $key !== '' ? Crypto::fromBase64($key) : Crypto::unconfigured();
        $isLab = $env('ENV') === 'lab';
        $talkUrl = (string) $env('URL', '');

        $policy = new Service\AccessPolicy();
        $events = new EventBus($db);
        $http = new CurlHttpClient((int) $env('HTTP_TIMEOUT', '10'));
        $erp = new TalkHelpdesk();
        $directory = new LocalContactDirectory($erp, $log);
        $helpdesk = new LocalHelpdesk($erp, $db, $talkUrl, $log);
        $jack = new Jack(
            $db,
            new Classifier(),
            (string) $env('JACK_MODE', 'auto'),
            (int) $env('JACK_WAIT_SECONDS', '5'),
            (int) $env('JACK_MAX_WAIT_SECONDS', '30'),
            typingBaseSeconds: (int) $env('JACK_TYPING_SECONDS', '20'),
        );
        // arquivos das conversas: fora da pasta pública, servidos por /media/{id} com conferência de acesso
        $media = new MediaStore(rtrim((string) $env('STORAGE', dirname(__DIR__, 3) . '/storage/talk'), '/'));
        $hours = new Service\BusinessHours($db);
        $conversations = new Service\ConversationService($db, $events, $policy, $directory, $helpdesk, jack: $jack, media: $media,
            askRating: $env('RATING', 'on') !== 'off', hours: $hours);
        $gateways = new GatewayFactory($db, $crypto, $http,
            ['base_url' => (string) $env('EVOLUTION_URL', ''), 'api_key' => (string) $env('EVOLUTION_API_KEY', '')],
            simulateQr: $isLab);

        return [
            'env' => $env,
            'db' => $db,
            'crypto' => $crypto,
            'policy' => $policy,
            'staff' => new Service\StaffRepository($db),
            'conversations' => $conversations,
            'inbox' => new Service\InboxQuery($db, $policy),
            'webhook' => new Service\WebhookHandler($gateways, $conversations),
            'worker' => new Service\OutboxWorker($db, $gateways, $events, $media),
            'media' => $media,
            'sse' => new SseStream($db, $policy),
            'gateways' => $gateways,
            'jack' => $jack,
            'hours' => $hours,
            'reports' => new Service\ReportService($db, $hours),
            'queue' => new Service\QueueQuery($db, $policy, (int) $env('QUEUE_WARN_MINUTES', '15'), (int) $env('QUEUE_LATE_MINUTES', '30'), $hours),
            'contactbook' => new Service\ContactBook($db, $conversations),
            'contacts' => new Service\ContactPanel($db, $policy, $events),
            'quick' => new Service\QuickMessages($db),
            'team' => new Service\TeamService($db, $events, $_SERVER['REMOTE_ADDR'] ?? null),
            'login' => new Service\LoginGuard($db),
            'erp_login' => new ErpLogin($db),
            'schedules' => new Service\ScheduleService($db, $policy, $conversations),
            'campaigns' => new Service\CampaignService($db, $gateways, $isLab),
            'channels' => new Service\ChannelService($db, $crypto, $gateways, $talkUrl, $isLab, (string) $env('EVOLUTION_WEBHOOK_URL', '')),
            'notifications' => new Service\Notifications($db, $policy, $hours),
        ];
    }
}
