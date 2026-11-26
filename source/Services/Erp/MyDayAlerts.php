<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access;

/**
 * Alerta das tarefas atrasadas (ADR 0658): todo dia às 7h, cada pessoa da equipe que tem algo que ficou para trás
 * no Meu Dia recebe um aviso no sininho ("Tarefas atrasadas no Meu Dia"), com quantas são e o dia da mais antiga.
 *
 * - Um aviso por pessoa por dia: rodar de novo no mesmo dia não repete.
 * - O aviso do dia anterior que ainda não foi lido é marcado como lido, para o sininho não acumular um por dia.
 * - Só sininho da equipe; nada vai para morador (regra dos 5 e-mails do morador, ADR 0601).
 */
final class MyDayAlerts
{
    public const TITLE = 'Tarefas atrasadas no Meu Dia';
    public const ROUTE = '/erp/meu-dia';

    private PDO $pdo;
    private MyDay $myDay;

    public function __construct(?PDO $pdo = null, ?MyDay $myDay = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->myDay = $myDay ?? new MyDay($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{people:int, late:int, notified:int, already:int} */
    public function run(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $result = ['people' => 0, 'late' => 0, 'notified' => 0, 'already' => 0];
        $category = (int)($this->pdo->query("SELECT id FROM notifications_categories WHERE uri = 'studio' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $ids = $this->pdo->query("SELECT id FROM users WHERE status <> 'trash' AND level >= 5 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $sent = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE users_id = :user AND title = :title AND created_at >= :day');
        $quiet = $this->pdo->prepare('UPDATE notifications SET view = 1, read_at = COALESCE(read_at, NOW()) WHERE users_id = :user AND title = :title AND view = 0');
        foreach ($ids as $id) {
            $user = (new User())->findById((int)$id);
            if ($user === null || !Access::can('erp.access', $user)) {
                continue;
            }
            $result['people']++;
            $overdue = $this->myDay->overdue($user, $today);
            if ($overdue['count'] === 0) {
                continue;
            }
            $result['late']++;
            $sent->execute(['user' => (int)$id, 'title' => self::TITLE, 'day' => $today->format('Y-m-d 00:00:00')]);
            if ((int)$sent->fetchColumn() > 0) {
                $result['already']++;
                continue;
            }
            $quiet->execute(['user' => (int)$id, 'title' => self::TITLE]);
            $this->pdo->prepare("INSERT INTO notifications (users_id, category, image, title, body, severity, link, view, expires_at)
                VALUES (:user, :category, 'images/default.svg', :title, :body, 'warning', :link, 0, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                ->execute([
                    'user' => (int)$id, 'category' => $category, 'title' => self::TITLE,
                    'body' => self::message($overdue['count'], $overdue['oldest']), 'link' => url(self::ROUTE),
                ]);
            $result['notified']++;
        }
        return $result;
    }

    public static function message(int $count, ?string $oldest): string
    {
        $what = $count === 1 ? '1 tarefa ficou para trás' : "{$count} tarefas ficaram para trás";
        $since = $oldest !== null ? ' (a mais antiga é de ' . date('d/m', (int)strtotime($oldest)) . ')' : '';
        return "{$what}{$since}. Elas estão em vermelho no Meu Dia de hoje até serem concluídas.";
    }
}
