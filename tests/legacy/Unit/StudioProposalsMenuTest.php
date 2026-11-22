<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Propostas do site em Studio > Gestão, com aviso no sininho e som (ADR 0637).
 */
final class StudioProposalsMenuTest extends TestCase
{
    private static function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /** Recorta o grupo do menu do Studio (o segundo bloco do $menu, depois do Help Desk). */
    private static function studioGroup(string $label): string
    {
        $layout = self::file('container/cms/studio/v1/layouts/studio.php');
        $studio = substr($layout, (int)strpos($layout, "['link', ['dash','/studio/dash','Dashboard'"));
        $start = strpos($studio, "['group', '{$label}'");
        self::assertNotFalse($start, "grupo {$label} no menu do Studio");
        $end = strpos($studio, ']],', $start);
        return substr($studio, $start, $end - $start);
    }

    public function testProposalsLiveInsideGestaoAndOnlyThere(): void
    {
        self::assertStringContainsString("['proposals','/studio/proposals','Propostas','document-text-outline','proposals.manage']", self::studioGroup('Gestão'));
        self::assertStringNotContainsString("/studio/proposals", self::studioGroup('Visão geral'));
        self::assertSame(1, substr_count(self::file('container/cms/studio/v1/layouts/studio.php'), "'/studio/proposals'"));
    }

    public function testBellPlaysSoundWhenCountGrows(): void
    {
        $layout = self::file('container/cms/studio/v1/layouts/studio.php');
        self::assertStringContainsString('assets/audio/notification.mp3', $layout);
        self::assertMatchesRegularExpression('/nextCount>studioLastNotificationCount\)\{studioNotifySound\?\.play\(\)/', $layout);
        self::assertFileExists(dirname(__DIR__, 3) . '/container/cms/studio/v1/assets/audio/notification.mp3');
    }

    public function testSiteFormSendsWhatTheServiceReads(): void
    {
        $form = self::file('container/web/default/v1/pages/sales.php');
        self::assertStringContainsString("action=\"<?= url('/solicite-sua-proposta') ?>\"", $form);
        self::assertStringContainsString('<?= csrf_input() ?>', $form);
        foreach (['name', 'email', 'whatsapp', 'condominio', 'units', 'profile', 'message', 'privacy', 'marketing', 'website'] as $field) {
            self::assertStringContainsString("name=\"{$field}\"", $form, "campo {$field} do formulário do site");
        }
        self::assertMatchesRegularExpression('/name="privacy" value="1" required/', $form, 'ciência da política obrigatória');
        self::assertDoesNotMatchRegularExpression('/name="marketing"[^>]*checked/', $form, 'novidades começa desmarcado');
        $routes = self::file('container/web/default/v1/routes.php');
        self::assertStringContainsString('$route->post("/solicite-sua-proposta", "Web:proposalSubmit");', $routes);
    }

    public function testTeamIsChosenByPermissionNotByFixedRoles(): void
    {
        $service = self::file('source/Support/Proposal/ProposalService.php');
        self::assertStringContainsString("can('proposals.manage')", $service);
        self::assertStringNotContainsString("r.slug IN (", $service);
    }
}
