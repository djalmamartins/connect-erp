<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Moradores da unidade no aplicativo (revisão de 15/11, etapa 4), iguais no web e no celular.
 *
 * Usa o mesmo cadastro do ERP (erp_residents, "Proprietários e inquilinos"), sem tabela paralela:
 * - ver: quem está ligado à unidade do vínculo (nome, vínculo e situação; CPF, e-mail e telefone não aparecem, LGPD);
 * - cadastrar: o proprietário cadastra inquilino e morador; o inquilino cadastra morador; o morador não cadastra;
 * - bloquear: só o proprietário, só inquilino ativo. Fica inativo com fim do vínculo hoje e perde o acesso ao app.
 *   Para "informar um novo", o proprietário cadastra o novo inquilino.
 * Tudo grava auditoria (o cadastro pelo RegistryService, o bloqueio aqui). Exige a função "household" do portal.
 */
final class ResidentHousehold
{
    public const FEATURE = 'household';
    /** Quem cada vínculo pode cadastrar. */
    public const CAN_ADD = ['owner' => ['tenant', 'resident'], 'tenant' => ['resident']];
    public const STATUS_LABELS = ['active' => 'Ativo', 'blocked' => 'Bloqueado'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Vínculos que esta pessoa pode cadastrar (regra pura). @return list<string> */
    public static function canAdd(string $relationship): array
    {
        return self::CAN_ADD[$relationship] ?? [];
    }

    /** Pode bloquear esta pessoa? Só o proprietário, só inquilino ativo, nunca a si mesmo (regra pura). */
    public static function canBlock(string $viewer, array $target, int $viewerResidentId): bool
    {
        return $viewer === 'owner' && (string)$target['relationship'] === 'tenant' && (string)$target['status'] === 'active'
            && (int)$target['id'] !== $viewerResidentId;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{can_add: list<string>}} */
    public function list(array $link, ?string $today = null): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $today ??= date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT id, name, relationship, status, starts_on, ends_on FROM erp_residents
            WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL
            ORDER BY FIELD(relationship, 'owner', 'tenant', 'resident', 'financial'), status, name, id");
        $stmt->execute(['u' => (int)$link['unit_id'], 'c' => (int)$link['condominium_id']]);
        $rows = array_map(fn (array $row): array => $this->present($link, $row, $today), $stmt->fetchAll(PDO::FETCH_ASSOC));
        // Bloqueado há mais de 90 dias sai da lista (fica no ERP).
        $since = (new \DateTimeImmutable($today))->modify('-90 days')->format('Y-m-d');
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'active' || ($r['ends_on'] ?? $today) >= $since));
        return ['data' => $rows, 'meta' => ['can_add' => self::canAdd((string)$link['relationship'])]];
    }

    /** @return array<string, mixed> a pessoa cadastrada */
    public function add(User $user, array $link, array $input, ?string $today = null, ?RegistryService $registry = null): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $relationship = is_string($input['relationship'] ?? null) ? $input['relationship'] : '';
        if (!in_array($relationship, self::canAdd((string)$link['relationship']), true)) {
            throw new ResidentNotAllowed((string)$link['relationship'] === 'owner'
                ? 'Escolha inquilino ou morador.' : ((string)$link['relationship'] === 'tenant'
                    ? 'O inquilino cadastra só moradores. Para outro vínculo, fale com o proprietário ou a administração.'
                    : 'Só o proprietário ou o inquilino cadastram moradores da unidade.'));
        }
        $values = array_intersect_key($input, array_flip(['name', 'document', 'email', 'phone', 'starts_on']));
        $values['relationship'] = $relationship;
        $values['unit_label'] = (string)$link['unit_label'];
        $values['starts_on'] = ($values['starts_on'] ?? '') !== '' ? $values['starts_on'] : ($today ?? date('Y-m-d'));
        $values['notes'] = 'Cadastrado pelo aplicativo do morador por ' . (string)$link['name'] . '.';
        $saved = ($registry ?? new RegistryService($this->pdo))->createForResident($user, 'residents', $values, (int)$link['condominium_id'], (int)$link['unit_id']);
        return $this->find($link, (int)$saved['id'], $today);
    }

    /** Bloqueia o inquilino: inativo, com fim do vínculo hoje. @return array<string, mixed> */
    public function block(User $user, array $link, int $id, ?string $today = null): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $today ??= date('Y-m-d');
        $row = $this->row($link, $id);
        if (!self::canBlock((string)$link['relationship'], $row, (int)$link['resident_id'])) {
            throw new ResidentNotAllowed((string)$link['relationship'] !== 'owner'
                ? 'Só o proprietário pode bloquear um inquilino.' : 'Só um inquilino ativo pode ser bloqueado.');
        }
        $endsOn = $row['ends_on'] !== null && (string)$row['ends_on'] < $today ? (string)$row['ends_on'] : $today;
        $stmt = $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive', ends_on = :e, updated_by = :by
            WHERE id = :id AND unit_id = :u AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute(['e' => $endsOn, 'by' => (int)$user->id, 'id' => $id, 'u' => (int)$link['unit_id'], 'c' => (int)$link['condominium_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RegistryNotFound();
        }
        Audit::record('update', 'erp_residents', $id, ['status' => 'active', 'ends_on' => $row['ends_on']],
            ['status' => 'inactive', 'ends_on' => $endsOn, 'channel' => 'resident_app', 'by_resident' => (int)$link['resident_id']]);
        return $this->find($link, $id, $today);
    }

    private function find(array $link, int $id, ?string $today): array
    {
        return $this->present($link, $this->row($link, $id), $today ?? date('Y-m-d'));
    }

    private function row(array $link, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, relationship, status, starts_on, ends_on FROM erp_residents
            WHERE id = :id AND unit_id = :u AND condominium_id = :c AND deleted_at IS NULL');
        $stmt->execute(['id' => $id, 'u' => (int)$link['unit_id'], 'c' => (int)$link['condominium_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function present(array $link, array $row, string $today): array
    {
        $ended = $row['ends_on'] !== null && (string)$row['ends_on'] < $today;
        $status = (string)$row['status'] === 'active' && !$ended ? 'active' : 'blocked';
        $item = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'relationship' => (string)$row['relationship'],
            'relationship_label' => ResidentLinks::RELATIONSHIPS[(string)$row['relationship']] ?? (string)$row['relationship'],
            'status' => $status, 'status_label' => self::STATUS_LABELS[$status],
            'starts_on' => $row['starts_on'] !== null ? (string)$row['starts_on'] : null,
            'ends_on' => $row['ends_on'] !== null ? (string)$row['ends_on'] : null,
            'is_me' => (int)$row['id'] === (int)$link['resident_id']];
        $item['can_block'] = self::canBlock((string)$link['relationship'], ['id' => $item['id'], 'relationship' => $item['relationship'], 'status' => $status], (int)$link['resident_id']);
        return $item;
    }
}
