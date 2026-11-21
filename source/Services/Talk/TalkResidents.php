<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentLinks;
use Source\Services\Support\TicketError;
use Source\Support\Audit;
use Source\Support\IpRateLimit;

/**
 * Consultas do Connect Talk em nome de um morador identificado (ADR 0632, escopo residents.read).
 *
 * O Talk manda o id do morador (achado antes por /talk/people) e o telefone de quem está falando no WhatsApp, no
 * cabeçalho X-Resident-Phone. O telefone tem de ser o do cadastro (mesmos 8 últimos dígitos, como na 2ª via). Com isso
 * o servidor monta o mesmo vínculo do aplicativo (condomínio, unidade, tipo de vínculo e funções ligadas na matriz do
 * portal) e as leituras reusam o ResidentPortal: o Talk vê exatamente o que o morador veria no app, nada além.
 *
 * Falha fechada: morador fora do escopo do usuário de integração, inativo, fora do período, sem unidade ativa, de
 * condomínio inativo ou com telefone diferente = 404, sem dizer o motivo. Depois de MISS_LIMIT erros na janela, o token
 * leva 429 (não dá para varrer ids e telefones). Toda consulta vai para a auditoria com o telefone mascarado.
 *
 * @phpstan-import-type Link from \Source\Services\Resident\ResidentPortal
 */
final class TalkResidents
{
    /** Erros de morador/telefone por token: 10 em 10 minutos. */
    public const MISS_LIMIT = [10, 600];

    private PDO $pdo;
    private CondominiumScope $scope;
    private IpRateLimit $limit;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?IpRateLimit $limit = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->limit = $limit ?? new IpRateLimit();
    }

    /**
     * Vínculo do morador, no mesmo formato do aplicativo (ResidentLinks::forUser).
     *
     * @return array{resident_id:int, name:string, relationship:string, relationship_label:string, condominium_id:int,
     *   condominium_name:string, unit_id:int, unit_label:string, features:list<string>}
     * @throws TicketError 422 telefone ausente; 404 morador não confere; 429 erros demais do token
     */
    public function link(?User $agent, int $residentId, string $phone, string $action, int $tokenId = 0, ?string $today = null, ?int $now = null): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw new TicketError('phone', 'Envie o telefone do morador, com DDD, no cabeçalho X-Resident-Phone.');
        }
        $row = $residentId > 0 ? $this->resident($agent, $residentId, $today ?? date('Y-m-d')) : null;
        $found = $row !== null && TalkHelpdesk::samePhone((string)$row['phone'], $digits);
        Audit::record('read', 'erp_residents', $residentId > 0 ? $residentId : null, null, ['origin' => 'talk', 'action' => $action,
            'phone' => TalkHelpdesk::maskPhone($digits), 'found' => $found]);
        if (!$found) {
            [$max, $window] = self::MISS_LIMIT;
            if ($this->limit->hit('talk-resident-miss', 'token:' . $tokenId, $max, $window, $now)) {
                throw new TicketError('resident_id', 'Muitas consultas de morador que não conferem. Tente de novo em alguns minutos.', 429);
            }
            throw new TicketError('resident_id', 'Morador não encontrado.', 404);
        }
        $condominium = (int)$row['condominium_id'];
        $enabled = [];
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            $enabled[$feature] = ResidentPortalPermissions::enabled($this->pdo, $condominium, $feature);
        }
        $relationship = (string)$row['relationship'];
        return [
            'resident_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'relationship' => $relationship,
            'relationship_label' => ResidentLinks::RELATIONSHIPS[$relationship] ?? $relationship,
            'condominium_id' => $condominium,
            'condominium_name' => (string)$row['condominium_name'],
            'unit_id' => (int)$row['unit_id'],
            'unit_label' => (string)$row['unit_label'],
            'features' => ResidentLinks::visibleFeatures($enabled, $relationship),
        ];
    }

    /** O que o Talk mostra do vínculo: só o primeiro nome, para cumprimentar. Sem CPF, e-mail ou telefone. */
    public static function present(array $link): array
    {
        $first = strtok(trim((string)$link['name']), ' ');
        return [
            'resident_id' => (int)$link['resident_id'],
            'first_name' => $first !== false ? $first : '',
            'relationship' => (string)$link['relationship'],
            'relationship_label' => (string)$link['relationship_label'],
            'condominium' => ['id' => (int)$link['condominium_id'], 'name' => (string)$link['condominium_name']],
            'unit' => ['id' => (int)$link['unit_id'], 'label' => (string)$link['unit_label']],
            'features' => array_values(array_diff($link['features'], ['boletos', 'household'])),
        ];
    }

    /** Morador ativo, no período, com unidade ativa, de condomínio ativo no escopo do usuário de integração. */
    private function resident(?User $agent, int $id, string $today): ?array
    {
        $allowed = $agent !== null ? $this->scope->allowedIds($agent) : [];
        if ($allowed === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.relationship, r.phone, r.starts_on, r.ends_on, r.condominium_id, c.name AS condominium_name,
                u.id AS unit_id, u.label AS unit_label
            FROM erp_residents r
            JOIN condominiums c ON c.id = r.condominium_id AND c.status = 'active'
            JOIN erp_units u ON u.id = r.unit_id AND u.condominium_id = r.condominium_id AND u.status = 'active' AND u.deleted_at IS NULL
            WHERE r.id = ? AND r.condominium_id IN ({$marks}) AND r.status = 'active' AND r.deleted_at IS NULL");
        $stmt->execute([$id, ...$allowed]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !ResidentLinks::activeOn($row['starts_on'] !== null ? (string)$row['starts_on'] : null,
            $row['ends_on'] !== null ? (string)$row['ends_on'] : null, $today)) {
            return null;
        }
        return $row;
    }
}
