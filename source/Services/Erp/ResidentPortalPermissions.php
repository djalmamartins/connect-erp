<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Matriz do portal do morador por condomínio (AP-153, ADR 0090). Tudo começa desligado: linha ausente,
 * condomínio inexistente ou função desconhecida = false. Aqui só fica a configuração; o portal do morador
 * consulta enabled() antes de mostrar cada função. Toda mudança vai para a auditoria com o antes e o depois.
 */
final class ResidentPortalPermissions
{
    // ADR 0302: o aplicativo do morador ganhou prestação de contas, encomendas, visitantes e assembleias.
    public const FEATURES = ['boletos' => 'Boletos e segunda via', 'reservations' => 'Reservas de áreas comuns', 'occurrences' => 'Ocorrências',
        'announcements' => 'Comunicados', 'documents' => 'Documentos do condomínio', 'accountability' => 'Prestação de contas',
        'deliveries' => 'Encomendas', 'visitors' => 'Visitantes', 'assemblies' => 'Assembleias'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Valor do pedido (regra pura): só true/false, 1/0 ou "true"/"false". Outro valor = erro, nunca ligado por engano. */
    public static function flag(mixed $value): bool
    {
        $flag = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($flag === null || $value === null || $value === '') {
            throw new InvalidArgumentException('Informe true ou false.');
        }
        return $flag;
    }

    /** Para o portal: a função está ligada neste condomínio? Qualquer dúvida = false (fail-closed). */
    public static function enabled(PDO $pdo, int $condominiumId, string $feature): bool
    {
        if (!isset(self::FEATURES[$feature]) || $condominiumId < 1) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('SELECT enabled FROM erp_resident_portal_permissions WHERE condominium_id = :c AND feature = :f');
            $stmt->execute(['c' => $condominiumId, 'f' => $feature]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{feature:string, label:string, enabled:bool, updated_at:?string}> */
    public function matrix(User $user, int $condominiumId): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT feature, enabled, updated_at FROM erp_resident_portal_permissions WHERE condominium_id = :c');
        $stmt->execute(['c' => $condominiumId]);
        $saved = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $saved[(string)$row['feature']] = $row;
        }
        $out = [];
        foreach (self::FEATURES as $feature => $label) {
            $out[] = ['feature' => $feature, 'label' => $label, 'enabled' => (int)($saved[$feature]['enabled'] ?? 0) === 1, 'updated_at' => $saved[$feature]['updated_at'] ?? null];
        }
        return $out;
    }

    public function set(User $admin, int $condominiumId, mixed $feature, mixed $value): array
    {
        $this->scope->assertAccess($admin, $condominiumId);
        if (!is_string($feature) || !isset(self::FEATURES[$feature])) {
            throw new InvalidArgumentException('Função do portal desconhecida.');
        }
        $enabled = self::flag($value);
        $before = self::enabled($this->pdo, $condominiumId, $feature);
        $this->pdo->prepare('INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled, updated_by) VALUES (:c, :f, :e, :u)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)')->execute(['c' => $condominiumId, 'f' => $feature, 'e' => $enabled ? 1 : 0, 'u' => (int)$admin->id]);
        if ($before !== $enabled) {
            Audit::record('update', 'erp_resident_portal_permissions', $condominiumId, ['feature' => $feature, 'enabled' => $before], ['feature' => $feature, 'enabled' => $enabled]);
        }
        return $this->matrix($admin, $condominiumId);
    }
}
