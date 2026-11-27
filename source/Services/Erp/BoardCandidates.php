<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access;
use Source\Support\Erp\BrDocument;

/**
 * Quem pode ser síndico ou conselheiro de um condomínio (ADR 0686, pedido do Djalma, 27/11/2026), somente leitura:
 * os moradores do prédio (proprietários, inquilinos, moradores e responsáveis financeiros ativos em erp_residents) e
 * os síndicos profissionais da Connect (erp_professional_managers, que valem para todos os condomínios).
 *
 * A tela "Síndico e conselho" usa a lista para sugerir nomes ao digitar e para o quadro "Escolher a pessoa"; ao
 * escolher, preenche nome, CPF/CNPJ, e-mail, telefone e unidade e grava o vínculo (resident_id ou
 * professional_manager_id). O servidor confere o vínculo de novo ao gravar (RegistryService, campos 'ref').
 *
 * Escopo (ADR-002): o condomínio precisa estar no escopo (fora = CondominiumAccessDenied, 404). Os moradores só saem
 * para quem também pode ver "Proprietários e inquilinos" (erp.residents.view, LGPD: mesma regra da tela deles);
 * sem ela, residents vem vazio e residents_allowed = false. A mesma pessoa em duas unidades aparece uma vez por
 * unidade (a unidade é o que o conselho registra).
 */
final class BoardCandidates
{
    public const MAX_RESIDENTS = 3000;
    public const MAX_PROFESSIONALS = 1000;

    private const RELATIONSHIPS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * @return array{condominium_id:int, residents_allowed:bool, residents:list<array<string, mixed>>, professionals:list<array<string, mixed>>}
     */
    public function list(User $user, mixed $condominiumId): array
    {
        $id = filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new InvalidArgumentException('Informe o condomínio (condominium_id).');
        }
        $this->scope->assertAccess($user, $id);
        $residentsAllowed = Access::can('erp.residents.view', $user);
        return [
            'condominium_id' => $id,
            'residents_allowed' => $residentsAllowed,
            'residents' => $residentsAllowed ? $this->residents($id) : [],
            'professionals' => $this->professionals(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function residents(int $condominiumId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.document, r.email, r.phone, r.relationship, r.unit_id,
                COALESCE(u.label, r.unit_label) AS unit_label
            FROM erp_residents r
            LEFT JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
            WHERE r.condominium_id = :c AND r.deleted_at IS NULL AND r.status = 'active'
            ORDER BY r.name, r.id LIMIT " . self::MAX_RESIDENTS);
        $stmt->execute(['c' => $condominiumId]);
        return array_map(static fn (array $r): array => self::person($r) + [
            'unit_id' => $r['unit_id'] === null ? null : (int)$r['unit_id'],
            'unit_label' => (string)($r['unit_label'] ?? ''),
            'relationship' => (string)$r['relationship'],
            'relationship_label' => self::RELATIONSHIPS[(string)$r['relationship']] ?? 'Morador',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    private function professionals(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, document, email, phone, contact_name FROM erp_professional_managers
            WHERE deleted_at IS NULL AND status = 'active' ORDER BY name, id LIMIT " . self::MAX_PROFESSIONALS);
        return array_map(static fn (array $r): array => self::person($r) + ['contact_name' => (string)($r['contact_name'] ?? '')],
            $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dados que a tela copia para o membro. O documento vai completo porque é o que o formulário grava; quem chama já
     * tem acesso à pessoa (síndico e conselho + proprietários e inquilinos, ou o cadastro de síndicos profissionais).
     *
     * @param array<string, mixed> $r
     * @return array{id:int, name:string, document:string, document_formatted:string, email:string, phone:string}
     */
    private static function person(array $r): array
    {
        $document = (string)($r['document'] ?? '');
        return [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'document' => $document,
            'document_formatted' => $document === '' ? '' : BrDocument::format($document),
            'email' => (string)($r['email'] ?? ''),
            'phone' => (string)($r['phone'] ?? ''),
        ];
    }
}
