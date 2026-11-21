<?php

declare(strict_types=1);

namespace Source\Services\Support;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\AccountabilityDeadline;
use Source\Services\Help\SupportContact;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Balão do SAC (ADR 0631): quem da equipe está online, se o atendimento está no horário e o WhatsApp.
 *
 * - Online: pessoa da equipe com support.manage que abriu uma tela do ERP ou do SAC nos últimos
 *   5 minutos E que autorizou aparecer no balão (sac_public). Sem autorização, ela atende normalmente, só não aparece.
 * - Horário: dias úteis (sem feriados nacionais), das 8h às 18h por padrão; CONNECT_SAC_HOURS=HH:MM-HH:MM no .env muda.
 * - O site só recebe primeiro nome e foto de quem autorizou: nada de e-mail, sobrenome ou id.
 */
final class SacDesk
{
    public const ONLINE_MINUTES = 5;
    public const MAX_AGENTS = 3;
    private const TOUCH_SECONDS = 60;
    private const DEFAULT_HOURS = '08:00-18:00';

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Marca a pessoa como ativa no atendimento. No máximo uma gravação por minuto por sessão.
     * Silencioso: presença nunca derruba a página.
     */
    public function touch(?User $user): void
    {
        $key = 'sacSeenAt';
        $now = time();
        if ($user === null || (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$key]) && $now - (int)$_SESSION[$key] < self::TOUCH_SECONDS)) {
            return;
        }
        try {
            if (!Access::can('support.manage', $user)) {
                return;
            }
            $this->pdo()->prepare('UPDATE users SET sac_seen_at = NOW() WHERE id = ?')->execute([(int)$user->id]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$key] = $now;
            }
        } catch (\Throwable) {
            // Sem a coluna (migration ainda não aplicada) ou banco fora: segue sem presença.
        }
    }

    /** A própria pessoa autoriza (ou retira) aparecer no balão do site. */
    public function setPublic(User $user, bool $public): void
    {
        $this->pdo()->prepare('UPDATE users SET sac_public = ?, sac_public_at = NOW() WHERE id = ?')->execute([$public ? 1 : 0, (int)$user->id]);
        Audit::record('update', 'users', (int)$user->id, null, ['sac_public' => $public ? 1 : 0]);
    }

    public function isPublic(User $user): bool
    {
        $stmt = $this->pdo()->prepare('SELECT sac_public FROM users WHERE id = ?');
        $stmt->execute([(int)$user->id]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array{0:string,1:string} início e fim do expediente (HH:MM) */
    public static function hours(?string $setting = null): array
    {
        $setting ??= (string)(getenv('CONNECT_SAC_HOURS') ?: self::DEFAULT_HOURS);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', trim($setting), $m) || "{$m[3]}:{$m[4]}" <= "{$m[1]}:{$m[2]}") {
            $setting = self::DEFAULT_HOURS;
        }
        return explode('-', trim($setting));
    }

    /** No horário: dia útil sem feriado nacional e dentro do expediente (regra pura). */
    public static function isOpen(DateTimeImmutable $now, ?string $setting = null): bool
    {
        [$from, $to] = self::hours($setting);
        $time = $now->format('H:i');
        return AccountabilityDeadline::isBusinessDay($now) && $time >= $from && $time < $to;
    }

    /** "segunda a sexta, das 8h às 18h" (regra pura). */
    public static function hoursLabel(?string $setting = null): string
    {
        $fmt = static fn (string $t): string => (int)substr($t, 0, 2) . 'h' . (substr($t, 3, 2) !== '00' ? substr($t, 3, 2) : '');
        [$from, $to] = self::hours($setting);
        return 'segunda a sexta, das ' . $fmt($from) . ' às ' . $fmt($to);
    }

    /**
     * O que o balão mostra. Fora do horário, ninguém aparece online.
     *
     * @param callable(string):?string|null $photoUrl transforma o caminho da foto em endereço público
     * @return array{open:bool, agents:list<array{name:string,initials:string,photo:?string}>, hours:string, whatsapp:?string}
     */
    public function status(?DateTimeImmutable $now = null, ?callable $photoUrl = null): array
    {
        $now ??= new DateTimeImmutable();
        $open = self::isOpen($now);
        $agents = [];
        if ($open) {
            try {
                $stmt = $this->pdo()->prepare("SELECT first_name, photo FROM users
                    WHERE sac_public = 1 AND status <> 'trash' AND level >= 5 AND sac_seen_at >= ?
                    ORDER BY sac_seen_at DESC LIMIT " . self::MAX_AGENTS);
                $stmt->execute([$now->modify('-' . self::ONLINE_MINUTES . ' minutes')->format('Y-m-d H:i:s')]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $name = trim((string)$row['first_name']);
                    $photo = trim((string)($row['photo'] ?? ''));
                    $agents[] = [
                        'name' => $name !== '' ? $name : 'Equipe',
                        'initials' => mb_strtoupper(mb_substr($name !== '' ? $name : 'C', 0, 1)),
                        'photo' => $photo !== '' && $photoUrl !== null ? $photoUrl($photo) : null,
                    ];
                }
            } catch (\Throwable) {
                $agents = [];
            }
        }
        $whatsapp = SupportContact::data()['whatsapp_url'];
        return [
            'open' => $open,
            'agents' => $agents,
            'hours' => self::hoursLabel(),
            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
        ];
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
