<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\AssemblyReadiness;

/**
 * ADR 0211: conferência antes da assembleia. Unit da regra pura evaluate() e do contrato de reuso (Assemblies e
 * UnitBatch); a leitura dos dados reais é conferida no MariaDB (Integration\AssemblyReadinessTest).
 */
final class AssemblyReadinessTest extends TestCase
{
    private const M = 1000000;

    public function testEvaluatePureRule(): void
    {
        $ok = AssemblyReadiness::evaluate($this->facts());
        self::assertSame([true, 0, 0, ['no_second_call']], [$ok['ready'], $ok['errors'], $ok['warnings'], array_column($ok['checks'], 'code')]);
        self::assertSame([100.0, true], [$ok['items'][0]['eligible_percent'], $ok['items'][0]['reachable']]);
        self::assertSame([], array_column(AssemblyReadiness::evaluate($this->facts(['second_call_at' => '2026-11-25 19:30:00']))['checks'], 'code'));

        $late = AssemblyReadiness::evaluate($this->facts(['first_call_at' => '2026-11-19 09:59:00']));
        self::assertSame(['notice_deadline', 'error'], [$late['checks'][0]['code'], $late['checks'][0]['level']], 'Menos de 8 dias.');
        self::assertTrue(AssemblyReadiness::evaluate($this->facts(['first_call_at' => '2026-11-19 10:00:00']))['ready'], 'Exatamente 8 dias.');
        self::assertTrue(AssemblyReadiness::evaluate($this->facts(['first_call_at' => '2026-11-12 19:00:00', 'status' => 'convened']))['ready'], 'Já convocada: o prazo não conta.');

        $missing = AssemblyReadiness::evaluate($this->facts(['missing_fraction' => 2, 'fraction_complete' => false, 'fraction_sum' => 0.8]));
        self::assertSame([['fraction_missing', 'error', 2]], array_map(static fn (array $c): array => [$c['code'], $c['level'], $c['count']],
            array_values(array_filter($missing['checks'], static fn (array $c): bool => $c['level'] !== 'info'))));
        $byUnit = $this->facts(['missing_fraction' => 2, 'fraction_complete' => false]);
        $byUnit['items'][0]['vote_basis'] = 'unit';
        self::assertTrue(AssemblyReadiness::evaluate($byUnit)['ready'], 'Item por unidade não depende da fração.');
        $sum = AssemblyReadiness::evaluate($this->facts(['fraction_complete' => false, 'fraction_sum' => 0.98]));
        self::assertSame(['fraction_sum', 'warning'], [$sum['checks'][0]['code'], $sum['checks'][0]['level']]);
        self::assertStringContainsString('98,0000%', $sum['checks'][0]['message']);

        foreach ([['simple', 1, true], ['absolute', 500000, false], ['absolute', 500001, true], ['two_thirds', 600000, false], ['two_thirds', 666667, true],
            ['unanimity', 999999, false], ['unanimity', self::M, true]] as [$quorum, $eligible, $reachable]) {
            $facts = $this->facts(['debtors' => $eligible === self::M ? 0 : 1]);
            $facts['items'][0] = ['quorum' => $quorum, 'eligible' => $eligible] + $facts['items'][0];
            $r = AssemblyReadiness::evaluate($facts);
            self::assertSame($reachable, $r['items'][0]['reachable'], "{$quorum} com {$eligible}");
            self::assertSame($reachable, !in_array('quorum_unreachable', array_column($r['checks'], 'code'), true), "{$quorum} com {$eligible}");
        }
        $debt = AssemblyReadiness::evaluate($this->facts(['debtors' => 3]));
        self::assertSame(['debtors', 'warning', 3], [$debt['checks'][0]['code'], $debt['checks'][0]['level'], $debt['checks'][0]['count']]);

        $empty = $this->facts(['units' => 0]);
        $empty['items'][0] = ['total' => 0, 'eligible' => 0] + $empty['items'][0];
        self::assertSame(['no_units'], array_column(array_filter(AssemblyReadiness::evaluate($empty)['checks'], static fn (array $c): bool => $c['level'] === 'error'), 'code'),
            'Sem unidade: só o erro no_units, sem um erro de quórum por item.');
    }

    public function testReusesTheAssemblyRulesAndIsReadOnly(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/AssemblyReadiness.php');
        foreach (['$assemblies->find($user, $assemblyId)', 'Assemblies::decide(', 'Assemblies::weight(', '$assemblies->overdueCents(', '$assemblies->totalWeight(',
            'Assemblies::MIN_NOTICE_DAYS', '->measures($user, $condominium)'] as $needle) {
            self::assertStringContainsString($needle, $service, 'Reusa: ' . $needle);
        }
        foreach (['INSERT ', 'UPDATE ', 'Audit::record', 'NotificationOutbox', 'Announcements', 'SELECT '] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Somente leitura, sem consulta paralela: ' . $needle);
        }
        $assemblies = (string)file_get_contents($root . '/source/Services/Erp/Assemblies.php');
        self::assertStringContainsString('public function overdueCents(int $condominium, int $unit, string $asOf): int', $assemblies);
        self::assertStringContainsString('public function totalWeight(int $condominium, string $basis): int', $assemblies);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AssemblyReadiness.php');
        foreach (["Access::can('erp.assemblies.view', \$user)", "Access::can('erp.access', \$user)", 'catch (RegistryNotFound)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/assemblies/{id}/readiness", "AssemblyReadiness:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function facts(array $override = []): array
    {
        return $override + ['status' => 'draft', 'first_call_at' => '2026-11-25 19:00:00', 'second_call_at' => null, 'now' => '2026-11-11 10:00:00',
            'units' => 10, 'debtors' => 0, 'missing_fraction' => 0, 'fraction_sum' => 1.0, 'fraction_complete' => true,
            'items' => [['id' => 1, 'position' => 1, 'title' => 'Aprovar contas', 'vote_basis' => 'fraction', 'quorum' => 'simple', 'total' => self::M, 'eligible' => self::M]]];
    }
}
