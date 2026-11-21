<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Talk\TalkResidents;

/** Consultas do Talk por morador (ADR 0632): o que volta do vínculo, rotas e OpenAPI. */
final class TalkResidentsRoutesTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testPresentKeepsOnlyFirstNameAndHidesBoletosAndHousehold(): void
    {
        $out = TalkResidents::present(['resident_id' => 7, 'name' => '  Maria da Silva Souza', 'relationship' => 'owner', 'relationship_label' => 'Proprietário',
            'condominium_id' => 3, 'condominium_name' => 'Alpha', 'unit_id' => 9, 'unit_label' => 'A - 101',
            'features' => ['boletos', 'reservations', 'household', 'deliveries']]);
        self::assertSame(['resident_id' => 7, 'first_name' => 'Maria', 'relationship' => 'owner', 'relationship_label' => 'Proprietário',
            'condominium' => ['id' => 3, 'name' => 'Alpha'], 'unit' => ['id' => 9, 'label' => 'A - 101'], 'features' => ['reservations', 'deliveries']], $out);
    }

    public function testRoutesAndOpenApiAreWired(): void
    {
        $index = $this->file('index.php');
        $openapi = $this->file('docs/api/openapi.yaml');
        $routes = ['' => 'resident', '/announcements' => 'residentAnnouncements', '/common-areas' => 'residentCommonAreas',
            '/common-areas/{area}/availability' => 'residentAreaAvailability', '/reservations' => 'residentReservations',
            '/deliveries' => 'residentDeliveries', '/visits' => 'residentVisits', '/assemblies' => 'residentAssemblies',
            '/assemblies/{assembly}' => 'residentAssembly', '/documents' => 'residentDocuments'];
        foreach ($routes as $path => $method) {
            self::assertStringContainsString('$route->get("/talk/residents/{id}' . $path . '", "Talk:' . $method . '", null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
            self::assertStringContainsString('  /talk/residents/{id}' . $path . ":\n", $openapi);
        }
        self::assertStringContainsString('X-Resident-Phone', $openapi);
        self::assertStringContainsString("agent('residents.read')", $this->file('source/Controllers/Api/V1/Talk.php'));
    }
}
