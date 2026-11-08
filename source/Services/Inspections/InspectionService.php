<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Vistoria presencial de condomínios: modelos, planos por condomínio, check-in por GPS,
 * checklist, fotos e conclusão. Único ponto de regra para a tela (/erp/operacional/vistorias)
 * e para a API (/api/v1/inspections). Todo acesso a uma vistoria passa pelo escopo de
 * condomínios do usuário (CondominiumScope, ADR-002).
 */
final class InspectionService
{
    public const VIEW = 'erp.inspections.view';
    public const PERFORM = 'erp.inspections.perform';
    public const MANAGE = 'erp.inspections.manage';
    public const MAX_PHOTOS_PER_ITEM = 6;
    public const RESULTS = ['ok' => 'Conforme', 'issue' => 'Com problema', 'not_applicable' => 'Não se aplica', 'pending' => 'Pendente'];
    public const CHECKIN = [
        'inside' => 'No condomínio',
        'outside' => 'Fora do raio do condomínio',
        'no_reference' => 'Condomínio sem localização cadastrada',
        'no_signal' => 'Sem localização do aparelho',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    private PhotoStorage $photos;

    public function __construct(?PDO $pdo = null, ?PhotoStorage $photos = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = new CondominiumScope($this->pdo);
        $this->photos = $photos ?? new PhotoStorage();
    }

    public function photos(): PhotoStorage
    {
        return $this->photos;
    }

    public function can(string $permission, ?User $user): bool
    {
        return $user !== null && Access::can($permission, $user);
    }

    /* ------------------------------------------------------------------ modelos */

    /** @return list<array<string, mixed>> */
    public function templates(bool $activeOnly = true): array
    {
        $sql = 'SELECT t.id, t.name, t.description, t.frequency, t.status, COUNT(i.id) AS items
            FROM inspection_templates t LEFT JOIN inspection_template_items i ON i.template_id = t.id'
            . ($activeOnly ? " WHERE t.status = 'active'" : '') . ' GROUP BY t.id ORDER BY t.name';
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'name' => (string)$row['name'], 'description' => $row['description'],
            'frequency' => (string)$row['frequency'], 'status' => (string)$row['status'], 'items' => (int)$row['items'],
        ], $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function template(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, description, frequency, status FROM inspection_templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT position, section, label, requires_photo FROM inspection_template_items WHERE template_id = ? ORDER BY position');
        $items->execute([$id]);
        $row['id'] = (int)$row['id'];
        $row['items'] = array_map(static fn(array $item): array => [
            'position' => (int)$item['position'], 'section' => (string)$item['section'],
            'label' => (string)$item['label'], 'requires_photo' => (bool)$item['requires_photo'],
        ], $items->fetchAll(PDO::FETCH_ASSOC));
        return $row;
    }

    /**
     * Cria ou altera um modelo. Itens no formato de ChecklistText. Vistorias já iniciadas
     * guardam cópia dos itens e não mudam.
     *
     * @param array{name?: mixed, description?: mixed, frequency?: mixed, status?: mixed, items?: mixed} $input
     */
    public function saveTemplate(User $user, ?int $id, array $input): int
    {
        $this->require(self::MANAGE, $user);
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $frequency = (string)($input['frequency'] ?? '');
        $status = (string)($input['status'] ?? 'active');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe um nome com até 120 caracteres.';
        }
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'Use no máximo 500 caracteres.';
        }
        if (!Schedule::valid($frequency)) {
            $errors['frequency'] = 'Escolha a periodicidade.';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Situação inválida.';
        }
        try {
            $items = ChecklistText::parse((string)($input['items'] ?? ''));
        } catch (InspectionError $error) {
            $errors += $error->errors;
            $items = [];
        }
        $duplicate = $this->pdo->prepare('SELECT id FROM inspection_templates WHERE name = ? AND id <> ?');
        $duplicate->execute([$name, (int)$id]);
        if ($name !== '' && $duplicate->fetchColumn()) {
            $errors['name'] = 'Já existe um modelo com este nome.';
        }
        if ($errors !== []) {
            throw InspectionError::invalid($errors);
        }

        $before = $id !== null ? $this->template($id) : null;
        if ($id !== null && $before === null) {
            throw InspectionError::notFound('Modelo');
        }
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO inspection_templates (name, description, frequency, status, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $description ?: null, $frequency, $status, (int)$user->id]);
                $id = (int)$this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE inspection_templates SET name = ?, description = ?, frequency = ?, status = ? WHERE id = ?')
                    ->execute([$name, $description ?: null, $frequency, $status, $id]);
                $this->pdo->prepare('DELETE FROM inspection_template_items WHERE template_id = ?')->execute([$id]);
            }
            $insert = $this->pdo->prepare('INSERT INTO inspection_template_items (template_id, position, section, label, requires_photo) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $insert->execute([$id, $item['position'], $item['section'], $item['label'], (int)$item['requires_photo']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        Audit::record($before === null ? 'create' : 'update', 'inspection_templates', $id,
            $before ? ['name' => $before['name'], 'frequency' => $before['frequency'], 'status' => $before['status'], 'items' => count($before['items'])] : null,
            ['name' => $name, 'frequency' => $frequency, 'status' => $status, 'items' => count($items)]);
        return $id;
    }

    /* --------------------------------------------------- planos e localização */

    /**
     * Quadro de vistorias devidas nos condomínios do usuário.
     *
     * @return list<array<string, mixed>>
     */
    public function board(User $user, ?DateTimeImmutable $today = null): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $today ??= new DateTimeImmutable('today');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id, p.condominium_id, c.name AS condominium, p.template_id, t.name AS template,
                p.frequency, p.starts_on, p.status,
                (SELECT MAX(i.completed_at) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'completed') AS last_completed_at,
                (SELECT MAX(i.id) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'in_progress') AS open_inspection_id
            FROM inspection_plans p
            JOIN condominiums c ON c.id = p.condominium_id
            JOIN inspection_templates t ON t.id = p.template_id
            WHERE p.condominium_id IN ({$in}) AND p.status = 'active' AND t.status = 'active'
            ORDER BY c.name, t.name");
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $last = $row['last_completed_at'] ? new DateTimeImmutable((string)$row['last_completed_at']) : null;
            $next = Schedule::nextDue((string)$row['frequency'], new DateTimeImmutable((string)$row['starts_on']), $last);
            $rows[] = [
                'plan_id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium' => (string)$row['condominium'],
                'template_id' => (int)$row['template_id'], 'template' => (string)$row['template'], 'frequency' => (string)$row['frequency'],
                'last_completed_at' => $row['last_completed_at'], 'next_due_on' => $next->format('Y-m-d'),
                'status' => $row['open_inspection_id'] ? 'in_progress' : Schedule::status((string)$row['frequency'], $next, $today),
                'open_inspection_id' => $row['open_inspection_id'] ? (int)$row['open_inspection_id'] : null,
            ];
        }
        $order = ['overdue' => 0, 'in_progress' => 1, 'due_soon' => 2, 'ok' => 3];
        usort($rows, static fn(array $a, array $b): int => [$order[$a['status']], $a['next_due_on']] <=> [$order[$b['status']], $b['next_due_on']]);
        return $rows;
    }

    /** @return list<array<string, mixed>> planos (ativos e inativos) dos condomínios do usuário */
    public function plans(User $user): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id, p.condominium_id, c.name AS condominium, p.template_id, t.name AS template, p.frequency, p.starts_on, p.status
            FROM inspection_plans p JOIN condominiums c ON c.id = p.condominium_id JOIN inspection_templates t ON t.id = p.template_id
            WHERE p.condominium_id IN ({$in}) ORDER BY c.name, t.name");
        $stmt->execute($ids);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium' => (string)$row['condominium'],
            'template_id' => (int)$row['template_id'], 'template' => (string)$row['template'], 'frequency' => (string)$row['frequency'],
            'starts_on' => (string)$row['starts_on'], 'status' => (string)$row['status'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array{condominium_id?: mixed, template_id?: mixed, frequency?: mixed, starts_on?: mixed, status?: mixed} $input */
    public function savePlan(User $user, array $input): int
    {
        $this->require(self::MANAGE, $user);
        $condominiumId = (int)($input['condominium_id'] ?? 0);
        $this->assertCondominium($user, $condominiumId);
        $template = $this->template((int)($input['template_id'] ?? 0));
        $frequency = (string)($input['frequency'] ?? '') ?: (string)($template['frequency'] ?? '');
        $startsOn = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($input['starts_on'] ?? '')) ?: null;
        $status = (string)($input['status'] ?? 'active');
        $errors = [];
        if ($template === null) {
            $errors['template_id'] = 'Escolha um modelo.';
        }
        if (!Schedule::valid($frequency)) {
            $errors['frequency'] = 'Escolha a periodicidade.';
        }
        if ($startsOn === null) {
            $errors['starts_on'] = 'Informe a data da primeira vistoria.';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Situação inválida.';
        }
        if ($errors !== []) {
            throw InspectionError::invalid($errors);
        }
        $this->pdo->prepare('INSERT INTO inspection_plans (condominium_id, template_id, frequency, starts_on, status, created_by) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE frequency = VALUES(frequency), starts_on = VALUES(starts_on), status = VALUES(status)')
            ->execute([$condominiumId, $template['id'], $frequency, $startsOn->format('Y-m-d'), $status, (int)$user->id]);
        $stmt = $this->pdo->prepare('SELECT id FROM inspection_plans WHERE condominium_id = ? AND template_id = ?');
        $stmt->execute([$condominiumId, $template['id']]);
        $id = (int)$stmt->fetchColumn();
        Audit::record('update', 'inspection_plans', $id, null, ['condominium_id' => $condominiumId, 'template_id' => $template['id'], 'frequency' => $frequency, 'status' => $status]);
        return $id;
    }

    /** @return array{latitude: float, longitude: float, radius_m: int}|null */
    public function geolocation(int $condominiumId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT latitude, longitude, radius_m FROM condominium_geolocations WHERE condominium_id = ?');
        $stmt->execute([$condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude'], 'radius_m' => (int)$row['radius_m']] : null;
    }

    /** @return array<int, array{latitude: float, longitude: float, radius_m: int}> por condomínio do usuário */
    public function geolocations(User $user): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT condominium_id, latitude, longitude, radius_m FROM condominium_geolocations WHERE condominium_id IN ({$in})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['condominium_id']] = ['latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude'], 'radius_m' => (int)$row['radius_m']];
        }
        return $out;
    }

    /** @param array{latitude?: mixed, longitude?: mixed, radius_m?: mixed} $input */
    public function saveGeolocation(User $user, int $condominiumId, array $input): void
    {
        $this->require(self::MANAGE, $user);
        $this->assertCondominium($user, $condominiumId);
        $lat = Geo::latitude($input['latitude'] ?? null);
        $lng = Geo::longitude($input['longitude'] ?? null);
        $radius = filter_var($input['radius_m'] ?? 150, FILTER_VALIDATE_INT, ['options' => ['min_range' => Geo::MIN_RADIUS_M, 'max_range' => Geo::MAX_RADIUS_M]]);
        $errors = [];
        if ($lat === null) {
            $errors['latitude'] = 'Latitude inválida.';
        }
        if ($lng === null) {
            $errors['longitude'] = 'Longitude inválida.';
        }
        if ($radius === false) {
            $errors['radius_m'] = 'Use um raio entre ' . Geo::MIN_RADIUS_M . ' e ' . Geo::MAX_RADIUS_M . ' metros.';
        }
        if ($errors !== []) {
            throw InspectionError::invalid($errors);
        }
        $before = $this->geolocation($condominiumId);
        $this->pdo->prepare('INSERT INTO condominium_geolocations (condominium_id, latitude, longitude, radius_m, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), radius_m = VALUES(radius_m), updated_by = VALUES(updated_by)')
            ->execute([$condominiumId, $lat, $lng, $radius, (int)$user->id]);
        Audit::record($before ? 'update' : 'create', 'condominium_geolocations', $condominiumId, $before, ['latitude' => $lat, 'longitude' => $lng, 'radius_m' => $radius]);
    }

    /* ----------------------------------------------------------------- vistoria */

    /**
     * Check-in: abre a vistoria com cópia dos itens do modelo e o resultado do GPS.
     * Fora do raio ou sem posição do aparelho exige justificativa, que vai para o relatório.
     *
     * @param array{condominium_id?: mixed, template_id?: mixed, latitude?: mixed, longitude?: mixed, accuracy?: mixed, checkin_note?: mixed} $input
     */
    public function start(User $user, array $input): int
    {
        $this->require(self::PERFORM, $user);
        $condominiumId = (int)($input['condominium_id'] ?? 0);
        $this->assertCondominium($user, $condominiumId);
        $template = $this->template((int)($input['template_id'] ?? 0));
        if ($template === null || $template['status'] !== 'active') {
            throw InspectionError::invalid(['template_id' => 'Escolha um modelo de checklist ativo.']);
        }
        $lat = Geo::latitude($input['latitude'] ?? null);
        $lng = Geo::longitude($input['longitude'] ?? null);
        if (($lat === null) !== ($lng === null)) {
            $lat = $lng = null;
        }
        $accuracy = Geo::accuracy($input['accuracy'] ?? null);
        $checkin = Geo::checkin($lat, $lng, $accuracy, $this->geolocation($condominiumId));
        $note = trim((string)($input['checkin_note'] ?? ''));
        if (in_array($checkin['status'], ['outside', 'no_signal'], true) && mb_strlen($note) < 5) {
            throw InspectionError::invalid(['checkin_note' => $checkin['status'] === 'outside'
                ? sprintf('Você está a %s m do condomínio. Explique o motivo para continuar.', number_format((float)$checkin['distance'], 0, ',', '.'))
                : 'Não recebemos a localização do aparelho. Explique o motivo para continuar.'], 'Check-in fora do condomínio.');
        }
        if (mb_strlen($note) > 500) {
            throw InspectionError::invalid(['checkin_note' => 'Use no máximo 500 caracteres.']);
        }
        $plan = $this->pdo->prepare("SELECT id FROM inspection_plans WHERE condominium_id = ? AND template_id = ? AND status = 'active'");
        $plan->execute([$condominiumId, $template['id']]);
        $planId = $plan->fetchColumn() ?: null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO inspections (condominium_id, template_id, plan_id, inspector_id, template_name, checkin_at,
                    checkin_latitude, checkin_longitude, checkin_accuracy_m, checkin_distance_m, checkin_status, checkin_note)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)')
                ->execute([$condominiumId, $template['id'], $planId, (int)$user->id, $template['name'], $lat, $lng, $accuracy,
                    $checkin['distance'], $checkin['status'], $note !== '' ? $note : null]);
            $id = (int)$this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO inspection_answers (inspection_id, position, section, label, requires_photo) VALUES (?, ?, ?, ?, ?)');
            foreach ($template['items'] as $item) {
                $insert->execute([$id, $item['position'], $item['section'], $item['label'], (int)$item['requires_photo']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        Audit::record('create', 'inspections', $id, null, ['condominium_id' => $condominiumId, 'template_id' => $template['id'], 'checkin_status' => $checkin['status']]);
        return $id;
    }

    /**
     * Vistoria completa (itens e fotos) dentro do escopo do usuário.
     *
     * @return array<string, mixed>
     * @throws InspectionError 404 quando não existe ou está fora do escopo
     */
    public function get(User $user, int $id): array
    {
        $this->require(self::VIEW, $user);
        $stmt = $this->pdo->prepare("SELECT i.*, c.name AS condominium, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS inspector
            FROM inspections i JOIN condominiums c ON c.id = i.condominium_id LEFT JOIN users u ON u.id = i.inspector_id WHERE i.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw InspectionError::notFound();
        }
        $answers = $this->pdo->prepare('SELECT id, position, section, label, requires_photo, result, note, answered_at FROM inspection_answers WHERE inspection_id = ? ORDER BY position');
        $answers->execute([$id]);
        $photos = $this->pdo->prepare('SELECT id, answer_id, width, height, latitude, longitude, created_at FROM inspection_photos WHERE inspection_id = ? ORDER BY id');
        $photos->execute([$id]);
        $byAnswer = [];
        foreach ($photos->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $byAnswer[(int)$photo['answer_id']][] = [
                'id' => (int)$photo['id'], 'width' => (int)$photo['width'], 'height' => (int)$photo['height'],
                'latitude' => $photo['latitude'] !== null ? (float)$photo['latitude'] : null,
                'longitude' => $photo['longitude'] !== null ? (float)$photo['longitude'] : null,
                'created_at' => (string)$photo['created_at'],
            ];
        }
        $items = [];
        $counts = ['pending' => 0, 'ok' => 0, 'issue' => 0, 'not_applicable' => 0];
        foreach ($answers->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $counts[$answer['result']]++;
            $items[] = [
                'id' => (int)$answer['id'], 'position' => (int)$answer['position'], 'section' => (string)$answer['section'],
                'label' => (string)$answer['label'], 'requires_photo' => (bool)$answer['requires_photo'],
                'result' => (string)$answer['result'], 'note' => $answer['note'], 'answered_at' => $answer['answered_at'],
                'photos' => $byAnswer[(int)$answer['id']] ?? [],
            ];
        }
        $deliveries = $this->pdo->prepare('SELECT recipient_email, recipient_name, queued_at FROM inspection_report_deliveries WHERE inspection_id = ? ORDER BY id');
        $deliveries->execute([$id]);
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium' => (string)$row['condominium'],
            'template_id' => (int)$row['template_id'], 'template' => (string)$row['template_name'],
            'plan_id' => $row['plan_id'] !== null ? (int)$row['plan_id'] : null,
            'inspector_id' => $row['inspector_id'] !== null ? (int)$row['inspector_id'] : null, 'inspector' => (string)$row['inspector'],
            'status' => (string)$row['status'],
            'checkin' => [
                'at' => (string)$row['checkin_at'], 'status' => (string)$row['checkin_status'],
                'latitude' => $row['checkin_latitude'] !== null ? (float)$row['checkin_latitude'] : null,
                'longitude' => $row['checkin_longitude'] !== null ? (float)$row['checkin_longitude'] : null,
                'accuracy_m' => $row['checkin_accuracy_m'] !== null ? (float)$row['checkin_accuracy_m'] : null,
                'distance_m' => $row['checkin_distance_m'] !== null ? (float)$row['checkin_distance_m'] : null,
                'note' => $row['checkin_note'],
            ],
            'summary' => $row['summary'], 'completed_at' => $row['completed_at'],
            'counts' => $counts, 'items' => $items,
            'deliveries' => $deliveries->fetchAll(PDO::FETCH_ASSOC),
            'editable' => $row['status'] === 'in_progress' && $this->canEdit($user, $row),
        ];
    }

    /**
     * Vistorias recentes dos condomínios do usuário.
     *
     * @param array{condominium_id?: mixed, status?: mixed} $filters
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, array $filters = [], int $limit = 50): array
    {
        $this->require(self::VIEW, $user);
        $ids = $this->scope->allowedIds($user);
        $condominium = (int)($filters['condominium_id'] ?? 0);
        if ($condominium > 0) {
            $ids = in_array($condominium, $ids, true) ? [$condominium] : [];
        }
        if ($ids === []) {
            return [];
        }
        $params = $ids;
        $where = 'i.condominium_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $status = (string)($filters['status'] ?? '');
        if (in_array($status, ['in_progress', 'completed', 'cancelled'], true)) {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare("SELECT i.id, i.condominium_id, c.name AS condominium, i.template_name, i.status, i.checkin_at, i.checkin_status, i.completed_at,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS inspector,
                SUM(a.result = 'issue') AS issues, SUM(a.result = 'pending') AS pending, COUNT(a.id) AS items
            FROM inspections i JOIN condominiums c ON c.id = i.condominium_id LEFT JOIN users u ON u.id = i.inspector_id
            LEFT JOIN inspection_answers a ON a.inspection_id = i.id
            WHERE {$where} GROUP BY i.id ORDER BY i.checkin_at DESC, i.id DESC LIMIT {$limit}");
        $stmt->execute($params);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium' => (string)$row['condominium'],
            'template' => (string)$row['template_name'], 'status' => (string)$row['status'], 'inspector' => (string)$row['inspector'],
            'checkin_at' => (string)$row['checkin_at'], 'checkin_status' => (string)$row['checkin_status'], 'completed_at' => $row['completed_at'],
            'items' => (int)$row['items'], 'issues' => (int)$row['issues'], 'pending' => (int)$row['pending'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array{result?: mixed, note?: mixed} $input */
    public function answer(User $user, int $inspectionId, int $answerId, array $input): void
    {
        $inspection = $this->editable($user, $inspectionId);
        $answer = $this->findAnswer($inspection, $answerId);
        $result = (string)($input['result'] ?? '');
        $note = trim((string)($input['note'] ?? ''));
        $errors = [];
        if (!in_array($result, ['ok', 'issue', 'not_applicable', 'pending'], true)) {
            $errors['result'] = 'Escolha Conforme, Com problema ou Não se aplica.';
        }
        if ($result === 'issue' && mb_strlen($note) < 3) {
            $errors['note'] = 'Descreva o problema encontrado.';
        }
        if (mb_strlen($note) > 1000) {
            $errors['note'] = 'Use no máximo 1000 caracteres.';
        }
        if ($errors !== []) {
            throw InspectionError::invalid($errors);
        }
        $this->pdo->prepare('UPDATE inspection_answers SET result = ?, note = ?, answered_by = ?, answered_at = IF(? = \'pending\', NULL, NOW()) WHERE id = ? AND inspection_id = ?')
            ->execute([$result, $note !== '' ? $note : null, (int)$user->id, $result, $answerId, $inspectionId]);
        Audit::record('update', 'inspection_answers', $answerId, ['result' => $answer['result'], 'note' => $answer['note']], ['result' => $result, 'note' => $note ?: null]);
    }

    /**
     * @param array<string, mixed> $upload item de $_FILES
     * @param array{latitude?: mixed, longitude?: mixed} $position posição do aparelho no momento da foto
     */
    public function addPhoto(User $user, int $inspectionId, int $answerId, array $upload, array $position = []): int
    {
        $inspection = $this->editable($user, $inspectionId);
        $answer = $this->findAnswer($inspection, $answerId);
        if (count($answer['photos']) >= self::MAX_PHOTOS_PER_ITEM) {
            throw InspectionError::invalid(['photo' => 'Cada item aceita até ' . self::MAX_PHOTOS_PER_ITEM . ' fotos.']);
        }
        $stored = $this->photos->store($upload, $inspection['condominium_id'], $inspectionId);
        $lat = Geo::latitude($position['latitude'] ?? null);
        $lng = Geo::longitude($position['longitude'] ?? null);
        if (($lat === null) !== ($lng === null)) {
            $lat = $lng = null;
        }
        try {
            $this->pdo->prepare('INSERT INTO inspection_photos (inspection_id, answer_id, path, mime, size_bytes, sha256, width, height, latitude, longitude, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$inspectionId, $answerId, $stored['path'], $stored['mime'], $stored['size_bytes'], $stored['sha256'],
                    $stored['width'], $stored['height'], $lat, $lng, (int)$user->id]);
        } catch (\Throwable $error) {
            $this->photos->delete($stored['path']);
            throw $error;
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'inspection_photos', $id, null, ['inspection_id' => $inspectionId, 'answer_id' => $answerId, 'sha256' => $stored['sha256']]);
        return $id;
    }

    public function deletePhoto(User $user, int $inspectionId, int $photoId): void
    {
        $this->editable($user, $inspectionId);
        $stmt = $this->pdo->prepare('SELECT path FROM inspection_photos WHERE id = ? AND inspection_id = ?');
        $stmt->execute([$photoId, $inspectionId]);
        $path = $stmt->fetchColumn();
        if ($path === false) {
            throw InspectionError::notFound('Foto');
        }
        $this->pdo->prepare('DELETE FROM inspection_photos WHERE id = ?')->execute([$photoId]);
        $this->photos->delete((string)$path);
        Audit::record('delete', 'inspection_photos', $photoId, ['inspection_id' => $inspectionId], null);
    }

    /**
     * Arquivo de uma foto para exibição, conferindo o escopo do condomínio.
     *
     * @return array{path: string, mime: string, sha256: string}
     */
    public function photoFile(User $user, int $inspectionId, int $photoId): array
    {
        $this->require(self::VIEW, $user);
        $stmt = $this->pdo->prepare('SELECT p.path, p.mime, p.sha256, i.condominium_id FROM inspection_photos p JOIN inspections i ON i.id = p.inspection_id WHERE p.id = ? AND p.inspection_id = ?');
        $stmt->execute([$photoId, $inspectionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw InspectionError::notFound('Foto');
        }
        $path = $this->photos->absolute((string)$row['path']);
        if ($path === null) {
            throw InspectionError::notFound('Foto');
        }
        return ['path' => $path, 'mime' => (string)$row['mime'], 'sha256' => (string)$row['sha256']];
    }

    /**
     * Conclui a vistoria. Exige todos os itens respondidos e foto nos itens marcados
     * com [foto] (exceto "Não se aplica"). Depois de concluída, a vistoria não muda mais.
     */
    public function complete(User $user, int $inspectionId, string $summary = ''): void
    {
        $inspection = $this->editable($user, $inspectionId);
        $summary = trim($summary);
        $errors = [];
        foreach ($inspection['items'] as $item) {
            if ($item['result'] === 'pending') {
                $errors['item_' . $item['id']] = 'Responda: ' . $item['label'];
            } elseif ($item['requires_photo'] && $item['result'] !== 'not_applicable' && $item['photos'] === []) {
                $errors['item_' . $item['id']] = 'Foto obrigatória: ' . $item['label'];
            }
        }
        if (mb_strlen($summary) > 4000) {
            $errors['summary'] = 'Use no máximo 4000 caracteres no resumo.';
        }
        if ($errors !== []) {
            throw InspectionError::invalid($errors, 'Ainda há itens a resolver antes de concluir.');
        }
        $stmt = $this->pdo->prepare("UPDATE inspections SET status = 'completed', completed_at = NOW(), summary = ? WHERE id = ? AND status = 'in_progress'");
        $stmt->execute([$summary !== '' ? $summary : null, $inspectionId]);
        if ($stmt->rowCount() !== 1) {
            throw InspectionError::conflict('A vistoria já foi concluída ou cancelada.');
        }
        Audit::record('update', 'inspections', $inspectionId, ['status' => 'in_progress'], ['status' => 'completed']);
    }

    public function cancel(User $user, int $inspectionId, string $reason): void
    {
        $this->editable($user, $inspectionId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw InspectionError::invalid(['reason' => 'Explique por que a vistoria foi cancelada.']);
        }
        $stmt = $this->pdo->prepare("UPDATE inspections SET status = 'cancelled', summary = ? WHERE id = ? AND status = 'in_progress'");
        $stmt->execute(['Cancelada: ' . $reason, $inspectionId]);
        if ($stmt->rowCount() !== 1) {
            throw InspectionError::conflict('A vistoria já foi concluída ou cancelada.');
        }
        Audit::record('update', 'inspections', $inspectionId, ['status' => 'in_progress'], ['status' => 'cancelled']);
    }

    /**
     * Destinatários válidos (até 10) a partir de texto livre separado por vírgula, ponto e vírgula ou linha.
     *
     * @return list<string>
     */
    public static function recipients(string $text): array
    {
        $emails = [];
        foreach (preg_split('/[\s,;]+/u', $text) ?: [] as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            if (!filter_var($candidate, FILTER_VALIDATE_EMAIL) || mb_strlen($candidate) > 190) {
                throw InspectionError::invalid(['recipients' => "E-mail inválido: {$candidate}"]);
            }
            $emails[$candidate] = true;
        }
        if ($emails === []) {
            throw InspectionError::invalid(['recipients' => 'Informe pelo menos um e-mail.']);
        }
        if (count($emails) > 10) {
            throw InspectionError::invalid(['recipients' => 'Envie para no máximo 10 e-mails de cada vez.']);
        }
        return array_keys($emails);
    }

    /** Registra o envio do relatório (a fila de e-mail fica a cargo de InspectionReport). */
    public function recordDelivery(User $user, int $inspectionId, string $email): void
    {
        $this->pdo->prepare('INSERT INTO inspection_report_deliveries (inspection_id, recipient_email, sent_by) VALUES (?, ?, ?)')
            ->execute([$inspectionId, $email, (int)$user->id]);
    }

    /* ---------------------------------------------------------------- internos */

    private function require(string $permission, ?User $user): void
    {
        if (!$this->can($permission, $user)) {
            throw InspectionError::forbidden();
        }
    }

    private function assertCondominium(User $user, int $condominiumId): void
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw InspectionError::invalid(['condominium_id' => 'Escolha um condomínio do seu acesso.']);
        }
    }

    /** Quem fez o check-in edita a própria vistoria; quem configura vistorias pode assumir qualquer uma. */
    private function canEdit(User $user, array $row): bool
    {
        return $this->can(self::PERFORM, $user)
            && ((int)($row['inspector_id'] ?? 0) === (int)$user->id || $this->can(self::MANAGE, $user));
    }

    /** @return array<string, mixed> */
    private function editable(User $user, int $inspectionId): array
    {
        $this->require(self::PERFORM, $user);
        $inspection = $this->get($user, $inspectionId);
        if ($inspection['status'] !== 'in_progress') {
            throw InspectionError::conflict('A vistoria já foi concluída ou cancelada e não pode mais ser alterada.');
        }
        if (!$inspection['editable']) {
            throw InspectionError::forbidden();
        }
        return $inspection;
    }

    /** @return array<string, mixed> */
    private function findAnswer(array $inspection, int $answerId): array
    {
        foreach ($inspection['items'] as $item) {
            if ($item['id'] === $answerId) {
                return $item;
            }
        }
        throw InspectionError::notFound('Item');
    }
}
