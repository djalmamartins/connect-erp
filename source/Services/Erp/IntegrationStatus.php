<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankAdapters;

/**
 * Integrações (AP-171, ADR 0091): só leitura. Mostra o estado de cada integração do ERP sem expor
 * credencial, endereço, host ou valor de ambiente; nada aqui liga, desliga ou testa conexão externa.
 * - banco: os adaptadores disponíveis (hoje só o sandbox, ADR 0035). Banco real depende de homologação;
 * - e-mail: o modo efetivo da entrega (log, sandbox ou production, NotificationDispatcher::deliveryMode)
 *   e se há caixa de teste configurada, sem mostrar o endereço;
 * - índices de correção: a última competência carregada de cada índice e se está atrasada;
 * - fila de avisos: pendentes e com falha na outbox, só dos condomínios do escopo.
 */
final class IntegrationStatus
{
    /** Índice com última competência anterior a este número de meses atrás está atrasado. */
    public const INDEX_STALE_MONTHS = 2;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação de um índice (regra pura): ok, stale (atrasado) ou missing (nunca carregado). */
    public static function indexState(?string $lastCompetence, string $today): string
    {
        if ($lastCompetence === null || preg_match('/^\d{4}-\d{2}$/', $lastCompetence) !== 1) {
            return 'missing';
        }
        $limit = (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-' . self::INDEX_STALE_MONTHS . ' months')->format('Y-m');
        return $lastCompetence < $limit ? 'stale' : 'ok';
    }

    /** Estado do e-mail (regra pura), sem o endereço da caixa de teste. */
    public static function mail(string $appEnv, string $configured, ?string $sandboxTo): array
    {
        $mode = NotificationDispatcher::deliveryMode($appEnv, $configured, $sandboxTo);
        return ['mode' => $mode, 'sandbox_inbox_configured' => $sandboxTo !== null && filter_var($sandboxTo, FILTER_VALIDATE_EMAIL) !== false,
            'reaches_residents' => $mode === 'production'];
    }

    public function overview(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        $indexes = [];
        foreach ($this->pdo->query('SELECT code, MAX(competence) AS last FROM erp_price_indexes GROUP BY code ORDER BY code')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[] = ['code' => (string)$row['code'], 'last_competence' => $row['last'], 'state' => self::indexState($row['last'], $today)];
        }
        $outbox = ['pending' => 0, 'failed' => 0];
        if ($ids !== []) {
            $rows = $this->pdo->query("SELECT status, COUNT(*) AS n FROM erp_notification_outbox WHERE condominium_id IN (" . implode(',', $ids) . ") AND status IN ('pending','failed') GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $outbox[(string)$row['status']] = (int)$row['n'];
            }
        }
        $banks = [];
        foreach (['sandbox'] as $name) {
            $banks[] = ['adapter' => $name, 'available' => BankAdapters::get($name) instanceof Banking\BankAdapter, 'production' => false];
        }
        return [
            'bank' => ['adapters' => $banks, 'note' => 'Banco real só depois de homologação, com credenciais no cofre e aprovação (ADR 0035).'],
            'mail' => self::mail(defined('CONF_APP_ENV') ? (string)CONF_APP_ENV : 'development', getenv('ERP_MAIL_DELIVERY') ?: 'log', getenv('ERP_MAIL_SANDBOX_TO') ?: null),
            'price_indexes' => $indexes,
            'outbox' => $outbox,
        ];
    }
}
