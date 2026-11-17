<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\ScopeTestInput;
use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\ResidentPortalPermissions;

/**
 * Revisão de IDOR do app do morador (ADR 0342): a moradora da unidade 101 do Alpha, com todas as funções do portal ligadas,
 * pede pelo id registros da unidade vizinha (102, mesmo condomínio) e do Beta. Precisa receber 403/404. Controle positivo: o
 * morador dono do registro faz o mesmo pedido e não recebe 401/403/404.
 *
 * rota => [método, ação do ResidentApp, tabela, alcance]. Alcance "unit": o registro é da unidade (vizinho e Beta recusados);
 * "condominium": é do condomínio inteiro (o vizinho pode ver; só o Beta é recusado).
 */
final class ResidentApiCrossUnitIntegrationTest extends TestCase
{
    use ScopeFixtures;

    private const ROUTES = [
        'GET /resident/entries/{id}' => ['GET', 'entry', 'erp_entries', 'unit'],
        'GET /resident/charges/{id}/pdf' => ['GET', 'chargePdf', 'erp_bank_charges', 'unit'],
        'GET /resident/certificates/{id}/pdf' => ['GET', 'certificatePdf', 'erp_unit_documents', 'unit'],
        'GET /resident/tickets/{id}' => ['GET', 'ticket', 'studio_support_tickets', 'unit'],
        'GET /resident/common-areas/{id}/availability' => ['GET', 'areaAvailability', 'erp_common_areas', 'condominium'],
        'GET /resident/assemblies/{id}' => ['GET', 'assembly', 'erp_assemblies', 'condominium'],
        'GET /resident/documents/{id}/file' => ['GET', 'documentFile', 'erp_documents', 'condominium'],
        'POST /resident/reservations/{id}/cancel' => ['POST', 'cancelReservation', 'erp_reservations', 'unit'],
        'POST /resident/tickets/{id}/messages' => ['POST', 'replyTicket', 'studio_support_tickets', 'unit'],
        'POST /resident/unit/residents/{id}/block' => ['POST', 'blockHousehold', 'erp_residents', 'unit'],
        'POST /resident/visits/{id}/cancel' => ['POST', 'cancelVisit', 'erp_visit_forecasts', 'unit'],
    ];

    /** Valores para a linha ser do jeito que o dono a enxerga. */
    private const FIXTURE = [
        'erp_entries' => ['nature' => 'receita', 'deleted_at' => null],
        'erp_unit_documents' => ['kind' => 'debt_certificate', 'status' => 'issued'],
        'erp_documents' => ['shared_with_residents' => 1, 'deleted_at' => null],
        'erp_reservations' => ['deleted_at' => null],
        'erp_visit_forecasts' => ['status' => 'active', 'deleted_at' => null],
        'erp_residents' => ['relationship' => 'tenant', 'deleted_at' => null],
        'erp_assemblies' => ['deleted_at' => null],
    ];

    /** Sem controle positivo, com o motivo; a recusa vem do filtro pela unidade/condomínio do vínculo, conferido no código. */
    private const NO_CONTROL = [
        'GET /resident/charges/{id}/pdf' => 'o PDF vem do banco (adaptador); a consulta filtra condomínio e unidade (BankCharges::residentPdf)',
        'GET /resident/certificates/{id}/pdf' => 'a certidão emitida exige dados de emissão; a trava compara unidade e condomínio (ResidentCertificates::document)',
        'GET /resident/documents/{id}/file' => 'precisa do arquivo no disco; a consulta filtra condomínio e compartilhamento (VirtualDisk::sharedFile)',
        'GET /resident/assemblies/{id}' => 'a assembleia precisa estar convocada; a consulta filtra o condomínio do vínculo',
        'POST /resident/unit/residents/{id}/block' => 'só proprietário bloqueia e precisa de vínculo ativo; a trava é a unidade do vínculo (ResidentHousehold::block)',
    ];

    /** Recusa como validação (422), sem devolver nada do registro. */
    private const REFUSED_AS_VALIDATION = [
        'GET /resident/common-areas/{id}/availability' => 'ResidentPortal::assertAreaOf: "Escolha uma área comum do seu condomínio."',
    ];

    private const BODY = '{"body":"Teste de escopo entre unidades","message":"Teste de escopo","reason":"Teste de escopo"}';

    private int $alpha;
    private int $beta;
    private User $ana;
    /** @var array<string, array{condominium:int, unit:int, owner:User}> */
    private array $places = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        foreach ([$this->alpha, $this->beta] as $condominium) {
            foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
                $this->pdo->prepare('INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled) VALUES (?, ?, 1)')->execute([$condominium, $feature]);
            }
        }
        $home = $this->unit($this->alpha, '101');
        $this->ana = $this->resident($this->alpha, $home);
        foreach (['vizinho' => [$this->alpha, '102'], 'beta' => [$this->beta, '201']] as $key => [$condominium, $number]) {
            $unit = $this->unit($condominium, $number);
            $this->places[$key] = ['condominium' => $condominium, 'unit' => $unit, 'owner' => $this->resident($condominium, $unit)];
        }
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        http_response_code(200);
        // A migração antiga do portal reaplica o enum sem "household" a cada execução da suíte: não deixar essa linha no banco.
        $this->pdo->exec("DELETE FROM erp_resident_portal_permissions WHERE feature = 'household'");
        parent::tearDown();
    }

    public function testRecordsOfOtherUnitsAndCondominiumsAreRefused(): void
    {
        $leaks = [];
        $unproven = [];
        foreach (self::ROUTES as $route => [$method, $action, $table, $reach]) {
            foreach ($this->places as $where => $place) {
                if ($reach === 'condominium' && $where === 'vizinho') {
                    continue;
                }
                $refs = ['condominium_id' => $place['condominium'], 'unit_id' => $place['unit'],
                    'requester_id' => (int)$place['owner']->id, 'created_by' => (int)$place['owner']->id];
                if ($table === 'erp_bank_charges') {
                    $refs['entry_id'] = $this->scopeRow('erp_entries', self::FIXTURE['erp_entries'], $refs);
                }
                $id = $this->scopeRow($table, self::FIXTURE[$table] ?? [], $refs);
                $status = $this->call($this->ana, $action, $id, $method);
                $refused = isset(self::REFUSED_AS_VALIDATION[$route]) ? [403, 404, 422] : [403, 404];
                if (!in_array($status, $refused, true)) {
                    $leaks[] = $route . ' (' . $where . ') => ' . $status;
                }
                if (!isset(self::NO_CONTROL[$route])) {
                    $control = $this->call($place['owner'], $action, $id, $method);
                    if (in_array($control, [401, 403, 404], true)) {
                        $unproven[] = $route . ' (' . $where . ') => ' . $control;
                    }
                }
            }
        }
        self::assertSame([], $leaks, 'O app do morador entregou ou alterou registro de outra unidade ou condomínio.');
        self::assertSame([], $unproven, 'Controle positivo falhou: a fixture não prova a recusa nessas rotas.');
    }

    private function call(User $user, string $action, int $id, string $method): int
    {
        Auth::actingAs($user);
        \Source\Support\Access::clear();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = [];
        $_POST = [];
        if ($method !== 'GET') {
            ScopeTestInput::$body = self::BODY;
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', ScopeTestInput::class);
        }
        ob_start();
        try {
            (new \Source\Controllers\Api\V1\ResidentApp())->$action(['id' => (string)$id]);
            $status = (int)http_response_code();
        } catch (\Throwable) {
            $status = 500;
        } finally {
            ob_end_clean();
            if ($method !== 'GET') {
                stream_wrapper_restore('php');
            }
        }
        return $status;
    }

    private function unit(int $condominium, string $number): int
    {
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, ?, 'apartamento')")->execute([$condominium, $number]);
        return (int)$this->pdo->lastInsertId();
    }

    private function resident(int $condominium, int $unit): User
    {
        $userId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, email, user_id) VALUES (?, ?, ?, 'owner', ?, ?, ?)")
            ->execute([$condominium, $unit, (string)$unit, 'Morador ' . $userId, 'm' . $userId . '@morador.test', $userId]);
        return (new User())->findById($userId);
    }
}
