<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Lembrete da próxima ação do atendimento de cobrança (revisão de 15/11, item 23, ADR 0315).
 *
 * Roda uma vez por dia (tools/collection-reminders.php, pelo cron, de manhã). Para cada unidade, só o contato mais
 * novo vale: se ele marcou a próxima ação para hoje (ou para um dia dos últimos LOOKBACK_DAYS que ainda não foi
 * lembrado), o aviso vai para o sino do sistema (notifications) de quem registrou o contato. Se essa pessoa não pode
 * mais atender (conta desativada, sem a permissão ou sem acesso ao condomínio), o aviso vai para a equipe da Connect
 * que pode atender aquele condomínio (erp.collections.manage). Cada contato é lembrado uma vez (reminded_at).
 * Nada sai para o morador: é só para a equipe.
 */
final class CollectionActionReminders
{
    public const PERMISSION = 'erp.collections.manage';
    public const LOOKBACK_DAYS = 7;
    public const ROUTE = '/erp/cobranca/atendimento-cobranca';

    private PDO $pdo;
    private DateTimeImmutable $today;

    public function __construct(?PDO $pdo = null, ?DateTimeImmutable $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->today = $today ?? new DateTimeImmutable('today');
    }

    /** Texto do lembrete (regra pura). */
    public static function message(string $unit, string $condominium, string $nextOn, string $today, string $outcome, ?string $promiseOn): string
    {
        $when = $nextOn === $today ? 'hoje' : 'em ' . date('d/m/Y', (int)strtotime($nextOn)) . ' (atrasada)';
        $promise = $promiseOn !== null ? ' Promessa de pagamento para ' . date('d/m/Y', (int)strtotime($promiseOn)) . '.' : '';
        return "Próxima ação de cobrança da unidade {$unit} do {$condominium} marcada para {$when}. Último contato: {$outcome}.{$promise}";
    }

    /** @return array{date: string, due: int, notified: int, to_agent: int, to_team: int, without_recipient: int} */
    public function run(): array
    {
        $today = $this->today->format('Y-m-d');
        $from = $this->today->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
        $result = ['date' => $today, 'due' => 0, 'notified' => 0, 'to_agent' => 0, 'to_team' => 0, 'without_recipient' => 0];
        // Só o contato mais novo de cada unidade: um contato depois substitui a ação marcada antes.
        $stmt = $this->pdo->prepare("SELECT cc.id, cc.condominium_id, cc.unit_id, cc.outcome, cc.promise_on, cc.next_action_on, cc.created_by,
                c.name AS condominium_name, u.label AS unit_label
            FROM erp_collection_contacts cc
            JOIN condominiums c ON c.id = cc.condominium_id
            JOIN erp_units u ON u.id = cc.unit_id
            WHERE cc.reminded_at IS NULL AND cc.next_action_on BETWEEN :from AND :today AND c.status = 'active'
              AND cc.id = (SELECT MAX(x.id) FROM erp_collection_contacts x WHERE x.unit_id = cc.unit_id)
            ORDER BY cc.next_action_on, cc.id");
        $stmt->execute(['from' => $from, 'today' => $today]);
        $category = (int)($this->pdo->query("SELECT id FROM notifications_categories WHERE uri = 'studio' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $mark = $this->pdo->prepare('UPDATE erp_collection_contacts SET reminded_at = NOW() WHERE id = :id AND reminded_at IS NULL');
        $teams = [];
        $holders = $this->holders();
        $scope = new CondominiumScope($this->pdo);
        $reach = function (int $userId, int $condominium) use ($holders, $scope): bool {
            if (!in_array($userId, $holders, true)) {
                return false;
            }
            $user = (new User())->findById($userId);
            return $user !== null && $scope->canAccess($user, $condominium);
        };
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['due']++;
            $condominium = (int)$row['condominium_id'];
            $agent = $row['created_by'] !== null ? (int)$row['created_by'] : 0;
            if ($agent > 0 && $reach($agent, $condominium)) {
                $recipients = [$agent];
                $result['to_agent']++;
            } else {
                $recipients = $teams[$condominium] ??= array_values(array_filter($holders, static fn (int $id): bool => $reach($id, $condominium)));
                if ($recipients !== []) {
                    $result['to_team']++;
                }
            }
            // Marca antes de avisar: se o envio cair no meio, a pessoa não recebe dois lembretes do mesmo contato.
            $mark->execute(['id' => (int)$row['id']]);
            if ($mark->rowCount() === 0) {
                continue;
            }
            if ($recipients === [] || $category === 0) {
                $result['without_recipient']++;
                continue;
            }
            $body = self::message((string)$row['unit_label'], (string)$row['condominium_name'], (string)$row['next_action_on'], $today,
                Collections::OUTCOMES[$row['outcome']] ?? (string)$row['outcome'], $row['promise_on'] !== null ? (string)$row['promise_on'] : null);
            foreach ($recipients as $userId) {
                $this->notify($userId, $category, $body, (int)$row['unit_id'], (string)$row['next_action_on'] < $today);
                $result['notified']++;
            }
        }
        return $result;
    }

    private function notify(int $userId, int $category, string $body, int $unitId, bool $late): void
    {
        $this->pdo->prepare("INSERT INTO notifications (users_id, category, image, title, body, severity, link, view, expires_at)
            VALUES (:user, :category, 'images/default.svg', :title, :body, :severity, :link, 0, DATE_ADD(NOW(), INTERVAL 1 MONTH))")
            ->execute([
                'user' => $userId, 'category' => $category,
                'title' => $late ? 'Ação de cobrança atrasada' : 'Ação de cobrança para hoje',
                'body' => mb_substr($body, 0, 500), 'severity' => $late ? 'warning' : 'info',
                'link' => url(self::ROUTE . '?unidade=' . $unitId) . '#atender',
            ]);
    }

    /** Contas confirmadas com a permissão de atender (mesma regra do AccountabilityDeadline). @return list<int> */
    private function holders(): array
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT u.id FROM users u
            JOIN access_user_roles ur ON ur.user_id = u.id
            JOIN access_roles r ON r.id = ur.role_id
            JOIN access_permissions p ON p.slug = :perm
            LEFT JOIN access_user_overrides o ON o.user_id = u.id AND o.permission_id = p.id
            WHERE u.status = 'confirmed'
              AND (o.effect = 'allow' OR (COALESCE(o.effect, '') <> 'deny'
                   AND (r.slug = 'developer' OR EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id))))
            ORDER BY u.id");
        $stmt->execute(['perm' => self::PERMISSION]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
