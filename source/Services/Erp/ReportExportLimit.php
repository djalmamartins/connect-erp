<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;

/**
 * Limite de exportações de relatório em CSV por usuário (ADR 0087, B5), no mesmo mecanismo do teto de
 * comunicados (ADR 0065): conta o que já foi feito na janela, sem tabela nova. Cada exportação já fica na
 * auditoria (action 'export', entity 'erp_reports'), então a conta é sobre system_audit_logs.
 */
final class ReportExportLimit
{
    public const MAX_PER_HOUR = 30;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Recusa (regra pura) quando já houve MAX_PER_HOUR exportações na última hora. */
    public static function exceeded(int $exportsInLastHour): bool
    {
        return $exportsInLastHour >= self::MAX_PER_HOUR;
    }

    /** Lança DomainException (o controller responde 429) se o usuário passou do limite. */
    public function assertAllowed(User $user): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE users_id = :u AND action = 'export' AND entity = 'erp_reports'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute(['u' => (int)$user->id]);
        if (self::exceeded((int)$stmt->fetchColumn())) {
            throw new DomainException(sprintf('Limite de %d exportações em CSV por hora. Tente mais tarde.', self::MAX_PER_HOUR));
        }
    }
}
