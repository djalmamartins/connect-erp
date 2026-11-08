<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Inspections\InspectionError;
use Source\Services\Inspections\InspectionReport;
use Source\Services\Inspections\InspectionService;
use Source\Services\Inspections\PhotoStorage;
use Source\Support\Access;

/** Vistoria presencial ponta a ponta no MariaDB de teste: escopo, check-in, checklist, fotos, conclusão e relatório. */
final class InspectionServiceTest extends TestCase
{
    private string $photoRoot;
    private InspectionService $service;
    private int $condo;
    private int $otherCondo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->photoRoot = sys_get_temp_dir() . '/insp-it-' . bin2hex(random_bytes(4)) . '/inspections';
        $this->service = new InspectionService($this->pdo, new PhotoStorage($this->photoRoot));
        $this->condo = $this->condominium('Residencial Alpha');
        $this->otherCondo = $this->condominium('Residencial Beta');
        $this->pdo->prepare('INSERT INTO condominium_geolocations (condominium_id, latitude, longitude, radius_m) VALUES (?, ?, ?, ?)')
            ->execute([$this->condo, -23.5503000, -46.6340000, 100]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->photoRoot)));
        parent::tearDown();
    }

    public function testFullFlowFromCheckinToReport(): void
    {
        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        $template = $this->template("# Portaria\nPortão\nCâmeras [foto]\n# Lazer\nPiscina");

        $id = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template,
            'latitude' => '-23.5506', 'longitude' => '-46.6340', 'accuracy' => '12']);
        $inspection = $this->service->get($inspector, $id);
        self::assertSame('inside', $inspection['checkin']['status']);
        self::assertEqualsWithDelta(33, $inspection['checkin']['distance_m'], 2);
        self::assertSame(3, count($inspection['items']));
        self::assertTrue($inspection['editable']);
        [$gate, $cameras, $pool] = array_column($inspection['items'], 'id');

        // Modelo editado depois não altera a vistoria já iniciada.
        $this->service->saveTemplate($this->manager(), $template, ['name' => 'Ronda', 'frequency' => 'weekly', 'items' => 'Outro item']);
        self::assertSame(3, count($this->service->get($inspector, $id)['items']));

        $this->service->answer($inspector, $id, $gate, ['result' => 'ok']);
        $this->assertInvalid(fn() => $this->service->answer($inspector, $id, $pool, ['result' => 'issue', 'note' => '']), 'note');
        $this->service->answer($inspector, $id, $pool, ['result' => 'issue', 'note' => 'Bomba da piscina parada']);
        $this->service->answer($inspector, $id, $cameras, ['result' => 'ok']);

        $errors = $this->assertInvalid(fn() => $this->service->complete($inspector, $id), 'item_' . $cameras);
        self::assertStringContainsString('Foto obrigatória', $errors['item_' . $cameras]);

        $photo = $this->service->addPhoto($inspector, $id, $cameras, ['tmp_name' => $this->jpeg(), 'error' => UPLOAD_ERR_OK], ['latitude' => '-23.5504', 'longitude' => '-46.6341']);
        $file = $this->service->photoFile($inspector, $id, $photo);
        self::assertFileExists($file['path']);
        self::assertStringStartsWith($this->photoRoot, $file['path']);

        $this->service->complete($inspector, $id, 'Tudo certo, exceto a piscina.');
        $done = $this->service->get($inspector, $id);
        self::assertSame('completed', $done['status']);
        self::assertSame(['pending' => 0, 'ok' => 2, 'issue' => 1, 'not_applicable' => 0], $done['counts']);
        self::assertFalse($done['editable']);

        $this->expectConflict(fn() => $this->service->answer($inspector, $id, $gate, ['result' => 'issue', 'note' => 'mudou']));
        $this->expectConflict(fn() => $this->service->complete($inspector, $id));

        $report = new InspectionReport($this->service, moves_container_path('erp', 'connect'));
        $html = $report->html($done, static fn(int $p): string => "/foto/{$p}");
        self::assertStringContainsString('Bomba da piscina parada', $html);
        self::assertStringContainsString('/foto/' . $photo, $html);
        $pdf = $report->pdf($inspector, $done);
        self::assertStringStartsWith('%PDF', $pdf);

        $queued = $report->queue($inspector, $done, ['sindico@test.local', 'conselho@test.local'], 'Segue a vistoria.');
        self::assertSame(2, $queued);
        $rows = $this->pdo->query("SELECT recipient_email, attachments_json, body FROM mail_queue ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['sindico@test.local', 'conselho@test.local'], array_column($rows, 'recipient_email'));
        $attachments = json_decode((string)$rows[0]['attachments_json'], true);
        $path = (string)array_key_first($attachments);
        self::assertStringStartsWith($this->photoRoot . '/reports/' . $this->condo . '/', $path);
        self::assertStringStartsWith('%PDF', (string)file_get_contents($path));
        self::assertStringContainsString('Bomba da piscina parada', (string)$rows[0]['body']);
        self::assertCount(2, $this->service->get($inspector, $id)['deliveries']);
    }

    public function testCheckinOutsideRadiusOrWithoutSignalRequiresJustification(): void
    {
        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo, $this->otherCondo]);
        $template = $this->template('Item');

        $errors = $this->assertInvalid(fn() => $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template, 'latitude' => '-23.5603', 'longitude' => '-46.6340']), 'checkin_note');
        self::assertStringContainsString('m do condomínio', $errors['checkin_note']);
        $this->assertInvalid(fn() => $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template]), 'checkin_note');

        $outside = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template, 'latitude' => '-23.5603', 'longitude' => '-46.6340', 'checkin_note' => 'GPS do prédio vizinho']);
        self::assertSame('outside', $this->service->get($inspector, $outside)['checkin']['status']);

        // Condomínio sem ponto cadastrado: registra a posição sem exigir justificativa.
        $noRef = $this->service->start($inspector, ['condominium_id' => $this->otherCondo, 'template_id' => $template, 'latitude' => '-22.9', 'longitude' => '-47.06']);
        self::assertSame('no_reference', $this->service->get($inspector, $noRef)['checkin']['status']);

        // Só latitude, sem longitude: tratado como sem sinal.
        $this->assertInvalid(fn() => $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template, 'latitude' => '-23.55']), 'checkin_note');
    }

    public function testScopeAndPermissionsAreEnforced(): void
    {
        $template = $this->template('Item');
        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        $id = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template, 'latitude' => '-23.5503', 'longitude' => '-46.6340']);
        $itemId = $this->service->get($inspector, $id)['items'][0]['id'];

        // Fora do escopo: 404, nunca 403, para não revelar a vistoria.
        $outsider = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->otherCondo]);
        $this->expectStatus(404, fn() => $this->service->get($outsider, $id));
        $this->expectStatus(404, fn() => $this->service->answer($outsider, $id, $itemId, ['result' => 'ok']));
        $this->assertInvalid(fn() => $this->service->start($outsider, ['condominium_id' => $this->condo, 'template_id' => $template]), 'condominium_id');
        self::assertSame([], $this->service->recent($outsider));

        // Só visualiza: lê, mas não altera nem inicia.
        $viewer = $this->user(['erp.inspections.view'], [$this->condo]);
        self::assertFalse($this->service->get($viewer, $id)['editable']);
        $this->expectStatus(403, fn() => $this->service->answer($viewer, $id, $itemId, ['result' => 'ok']));
        $this->expectStatus(403, fn() => $this->service->start($viewer, ['condominium_id' => $this->condo, 'template_id' => $template]));

        // Outro vistoriador do mesmo condomínio não altera a vistoria alheia; quem gerencia pode.
        $colleague = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        self::assertFalse($this->service->get($colleague, $id)['editable']);
        $this->expectStatus(403, fn() => $this->service->answer($colleague, $id, $itemId, ['result' => 'ok']));
        $this->service->answer($this->manager(), $id, $itemId, ['result' => 'ok']);

        // Sem nenhuma permissão erp.inspections.*: nega (prefixo estrito, sem fallback por nível).
        $nobody = $this->user([], [$this->condo], 10);
        $this->expectStatus(403, fn() => $this->service->get($nobody, $id));

        // Item de outra vistoria não pode ser respondido por esta.
        $other = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $template, 'latitude' => '-23.5503', 'longitude' => '-46.6340']);
        $this->expectStatus(404, fn() => $this->service->answer($inspector, $other, $itemId, ['result' => 'ok']));

        // Foto só sai para quem tem o condomínio no escopo.
        $photo = $this->service->addPhoto($inspector, $other, $this->service->get($inspector, $other)['items'][0]['id'], ['tmp_name' => $this->jpeg(), 'error' => UPLOAD_ERR_OK]);
        $this->expectStatus(404, fn() => $this->service->photoFile($outsider, $other, $photo));
        $this->expectStatus(404, fn() => $this->service->photoFile($inspector, $id, $photo), 'Foto de outra vistoria.');
    }

    public function testPlansBoardShowsWhatIsDue(): void
    {
        $manager = $this->manager();
        $weekly = $this->template('Item', 'Ronda semanal');
        $monthly = $this->template('Item', 'Vistoria mensal');
        $this->service->savePlan($manager, ['condominium_id' => $this->condo, 'template_id' => $weekly, 'frequency' => 'weekly', 'starts_on' => '2026-11-01']);
        $this->service->savePlan($manager, ['condominium_id' => $this->condo, 'template_id' => $monthly, 'frequency' => '', 'starts_on' => '2026-11-20']);
        $this->service->savePlan($manager, ['condominium_id' => $this->otherCondo, 'template_id' => $weekly, 'frequency' => 'weekly', 'starts_on' => '2026-11-01']);
        // Salvar de novo o mesmo par altera o plano, não duplica.
        $this->service->savePlan($manager, ['condominium_id' => $this->condo, 'template_id' => $monthly, 'frequency' => 'monthly', 'starts_on' => '2026-11-30']);
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM inspection_plans')->fetchColumn());

        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        $today = new DateTimeImmutable('2026-11-08');
        $board = $this->service->board($inspector, $today);
        self::assertSame([['Ronda semanal', 'overdue', '2026-11-01'], ['Vistoria mensal', 'ok', '2026-11-30']],
            array_map(static fn(array $r): array => [$r['template'], $r['status'], $r['next_due_on']], $board), 'Só os planos do escopo, atrasados primeiro.');

        $id = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $weekly, 'latitude' => '-23.5503', 'longitude' => '-46.6340']);
        self::assertSame('in_progress', $this->service->board($inspector, $today)[0]['status']);
        self::assertSame($id, $this->service->board($inspector, $today)[0]['open_inspection_id']);

        $item = $this->service->get($inspector, $id)['items'][0]['id'];
        $this->service->answer($inspector, $id, $item, ['result' => 'not_applicable']);
        $this->service->complete($inspector, $id);
        $this->pdo->prepare('UPDATE inspections SET completed_at = ? WHERE id = ?')->execute(['2026-11-07 10:00:00', $id]);
        $row = $this->service->board($inspector, $today)[0];
        self::assertSame(['Ronda semanal', 'ok', '2026-11-14'], [$row['template'], $row['status'], $row['next_due_on']]);

        $this->expectStatus(403, fn() => $this->service->savePlan($inspector, ['condominium_id' => $this->condo, 'template_id' => $weekly, 'starts_on' => '2026-11-01']));
        $this->assertInvalid(fn() => $this->service->savePlan($manager, ['condominium_id' => $this->condo, 'template_id' => 999, 'starts_on' => 'x']), 'template_id');
    }

    public function testTemplatesAndGeolocationManagement(): void
    {
        $manager = $this->manager();
        $id = $this->service->saveTemplate($manager, null, ['name' => 'Garagem', 'frequency' => 'monthly', 'items' => "# Garagem\nPiso [foto]\nIluminação"]);
        $template = $this->service->template($id);
        self::assertSame(['Piso', 'Iluminação'], array_column($template['items'], 'label'));
        self::assertTrue($template['items'][0]['requires_photo']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'inspection_templates'")->fetchColumn());

        $this->assertInvalid(fn() => $this->service->saveTemplate($manager, null, ['name' => 'Garagem', 'frequency' => 'monthly', 'items' => 'X']), 'name');
        $this->assertInvalid(fn() => $this->service->saveTemplate($manager, null, ['name' => 'Novo', 'frequency' => 'daily', 'items' => '']), 'frequency');
        $this->expectStatus(404, fn() => $this->service->saveTemplate($manager, 999, ['name' => 'Outro', 'frequency' => 'monthly', 'items' => 'X']));

        $this->service->saveTemplate($manager, $id, ['name' => 'Garagem', 'frequency' => 'monthly', 'status' => 'inactive', 'items' => 'X']);
        self::assertSame([], array_filter($this->service->templates(), static fn(array $t): bool => $t['id'] === $id), 'Modelo inativo some da lista de check-in.');
        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        $this->assertInvalid(fn() => $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $id]), 'template_id');

        $this->service->saveGeolocation($manager, $this->otherCondo, ['latitude' => '-22,9056', 'longitude' => '-47.0608', 'radius_m' => '200']);
        self::assertSame(['latitude' => -22.9056, 'longitude' => -47.0608, 'radius_m' => 200], $this->service->geolocation($this->otherCondo));
        $this->assertInvalid(fn() => $this->service->saveGeolocation($manager, $this->otherCondo, ['latitude' => '100', 'longitude' => '0', 'radius_m' => '5']), 'latitude');
        $this->expectStatus(403, fn() => $this->service->saveGeolocation($inspector, $this->condo, ['latitude' => '0', 'longitude' => '0']));
    }

    public function testCancelAndPhotoLimitsAndDeletion(): void
    {
        $inspector = $this->user(['erp.inspections.view', 'erp.inspections.perform'], [$this->condo]);
        $id = $this->service->start($inspector, ['condominium_id' => $this->condo, 'template_id' => $this->template('Item'), 'latitude' => '-23.5503', 'longitude' => '-46.6340']);
        $item = $this->service->get($inspector, $id)['items'][0]['id'];

        $photos = [];
        for ($i = 0; $i < InspectionService::MAX_PHOTOS_PER_ITEM; $i++) {
            $photos[] = $this->service->addPhoto($inspector, $id, $item, ['tmp_name' => $this->jpeg(), 'error' => UPLOAD_ERR_OK]);
        }
        $this->assertInvalid(fn() => $this->service->addPhoto($inspector, $id, $item, ['tmp_name' => $this->jpeg(), 'error' => UPLOAD_ERR_OK]), 'photo');

        $path = $this->service->photoFile($inspector, $id, $photos[0])['path'];
        $this->service->deletePhoto($inspector, $id, $photos[0]);
        self::assertFileDoesNotExist($path);
        $this->expectStatus(404, fn() => $this->service->photoFile($inspector, $id, $photos[0]));

        $this->assertInvalid(fn() => $this->service->cancel($inspector, $id, ''), 'reason');
        $this->service->cancel($inspector, $id, 'Portaria não liberou a entrada');
        self::assertSame('cancelled', $this->service->get($inspector, $id)['status']);
        $this->expectConflict(fn() => $this->service->addPhoto($inspector, $id, $item, ['tmp_name' => $this->jpeg(), 'error' => UPLOAD_ERR_OK]));
        $report = new InspectionReport($this->service, moves_container_path('erp', 'connect'));
        $this->expectConflict(fn() => $report->queue($inspector, $this->service->get($inspector, $id), ['a@test.local']));
    }

    /* ---------------------------------------------------------------- apoio */

    private function condominium(string $name): int
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Usuário com papel próprio, as permissões dadas e vínculo com os condomínios. */
    private function user(array $permissions, array $condominiums, int $level = 2): User
    {
        $userId = $this->createUser(['level' => $level]);
        $slug = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 10)')->execute([$slug, $slug]);
        $roleId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        foreach (['erp.inspections.view', 'erp.inspections.perform', 'erp.inspections.manage', CondominiumScope::ALL_PERMISSION] as $permission) {
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$permission, $permission]);
        }
        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$roleId, $permission]);
        }
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($userId, $condominium);
        }
        Access::clear();
        return (new User())->findById($userId);
    }

    private function manager(): User
    {
        return $this->user(['erp.inspections.view', 'erp.inspections.perform', 'erp.inspections.manage', CondominiumScope::ALL_PERMISSION], []);
    }

    private function template(string $items, string $name = 'Ronda'): int
    {
        return $this->service->saveTemplate($this->manager(), null, ['name' => $name, 'frequency' => 'weekly', 'items' => $items]);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(64, 48);
        $file = tempnam(sys_get_temp_dir(), 'insp');
        imagejpeg($image, $file);
        return $file;
    }

    /** @return array<string, string> */
    private function assertInvalid(callable $action, string $field): array
    {
        try {
            $action();
        } catch (InspectionError $error) {
            self::assertSame(422, $error->status, $error->getMessage());
            self::assertArrayHasKey($field, $error->errors, 'Erros: ' . json_encode($error->errors, JSON_UNESCAPED_UNICODE));
            return $error->errors;
        }
        self::fail("Esperava erro de validação em {$field}.");
    }

    private function expectConflict(callable $action): void
    {
        $this->expectStatus(409, $action);
    }

    private function expectStatus(int $status, callable $action, string $message = ''): void
    {
        try {
            $action();
        } catch (InspectionError $error) {
            self::assertSame($status, $error->status, $message . ' ' . $error->getMessage());
            return;
        }
        self::fail("Esperava status {$status}. {$message}");
    }
}
