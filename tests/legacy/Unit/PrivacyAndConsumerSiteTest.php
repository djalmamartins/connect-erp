<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Privacy\LegalDocuments;

/**
 * LGPD e CDC no site e no app (ADR 0603): nada de rastreador antes do consentimento, nada de depoimento inventado,
 * selo de loja sem app publicado ou número sem prova, e aceite real no pedido de proposta.
 */
final class PrivacyAndConsumerSiteTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function file(string $path): string
    {
        return (string)file_get_contents(self::root() . '/' . $path);
    }

    /** @return list<string> */
    private function scan(array $dirs, array $extensions): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (in_array($file->getExtension(), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    public function testNoTrackerLoadsWithoutConsent(): void
    {
        $offenders = [];
        $files = $this->scan(['container', 'apps/morador/app', 'apps/morador/src'], ['php', 'js', 'ts', 'tsx', 'html']);
        foreach ($files as $path) {
            $code = (string)file_get_contents($path);
            foreach (['fbq(', 'fbevents.js', 'UA-53658515', 'AW-953362805', 'hotjar', 'clarity.ms'] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = str_replace(self::root() . '/', '', $path) . ' (' . $needle . ')';
                }
            }
            if (str_contains($code, 'googletagmanager.com') && !str_ends_with($path, 'assets/js/cookie-consent.js') && !str_ends_with($path, 'assets/site.min.js')) {
                $offenders[] = str_replace(self::root() . '/', '', $path) . ' (googletagmanager fora do banner)';
            }
        }
        self::assertSame([], $offenders, 'rastreador carregando sem consentimento');
        self::assertFileDoesNotExist(self::root() . '/container/shared/assets/vendor/scripts/tracker.js');
        foreach (['Web', 'App'] as $bundle) {
            self::assertStringNotContainsString('tracker.js', $this->file("source/Services/Minify/{$bundle}.php"));
        }
    }

    public function testGoogleAnalyticsOnlyAfterStatsConsent(): void
    {
        $js = $this->file('container/web/default/v1/assets/js/cookie-consent.js');
        self::assertStringContainsString('if (granted.stats && ga && !window.__connectGa)', $js);
        self::assertStringContainsString('anonymize_ip: true', $js);
        self::assertStringContainsString('if (granted.maps)', $js, 'mapa só com a escolha');
        putenv('SITE_GA4_ID');
        self::assertSame('', LegalDocuments::analyticsId());
        self::assertArrayNotHasKey('stats', LegalDocuments::cookieCategories(), 'sem GA4 configurado, o banner não oferece estatística');
        putenv('SITE_GA4_ID=UA-123');
        self::assertSame('', LegalDocuments::analyticsId(), 'só GA4 (G-...)');
        putenv('SITE_GA4_ID=G-ABC123');
        self::assertSame('G-ABC123', LegalDocuments::analyticsId());
        self::assertArrayHasKey('stats', LegalDocuments::cookieCategories());
        putenv('SITE_GA4_ID');
    }

    public function testCookieBannerHasRejectWithTheSameWeightAsAccept(): void
    {
        $html = $this->file('container/web/default/v1/pages/cookies.php');
        preg_match_all('/<button type="button" class="([^"]+)" data-cookie-action="(reject|accept)"/', $html, $m);
        self::assertSame(['reject', 'accept'], $m[2], 'Recusar e Aceitar existem');
        self::assertSame($m[1][0], $m[1][1], 'mesmo estilo para Recusar e Aceitar');
        self::assertStringContainsString('csrf_input()', $html);
        foreach (['theme', 'sales'] as $layout) {
            self::assertStringContainsString('pages/cookies', $this->file("container/web/default/v1/layouts/{$layout}.php"));
        }
        self::assertStringContainsString('data-cookie-open', $this->file('container/web/default/v1/pages/footer.php'), 'rodapé reabre a escolha');
        self::assertStringContainsString('$route->post("/cookies", "Web:cookieConsent")', $this->file('container/web/default/v1/routes.php'));
        $home = $this->file('container/web/default/v1/pages/home.php');
        self::assertDoesNotMatchRegularExpression('/<iframe[^>]+google\.com\/maps/', $home, 'mapa do Google não carrega antes do clique');
        self::assertStringContainsString('data-consent-src="https://www.google.com/maps', $home);
    }

    public function testNoInventedTestimonialsOrUnprovenClaims(): void
    {
        $testimonials = $this->file('container/web/default/v1/pages/testimonials.php');
        foreach (['Juliana R.', 'Parque do Sol', 'Avenida Tower', 'Vista Verde'] as $fake) {
            self::assertStringNotContainsString($fake, $testimonials, 'depoimento de exemplo');
        }
        $home = $this->file('container/web/default/v1/pages/home.php');
        self::assertStringNotContainsString('96%', $home, 'satisfação sem pesquisa guardada (CDC art. 36)');
        self::assertStringNotContainsString('96%', $this->file('container/web/default/v1/pages/sales.php'));
        self::assertDoesNotMatchRegularExpression('/<a href="#"><img class="store"/', $home, 'selo de loja sem app publicado');
        self::assertStringContainsString("str_starts_with(\$playStore, 'https://play.google.com/')", $home);
    }

    public function testProposalFormAsksPrivacyAndSeparateOptionalMarketing(): void
    {
        $form = $this->file('container/web/default/v1/pages/sales.php');
        self::assertMatchesRegularExpression('/<input type="checkbox" name="privacy" value="1" required>/', $form);
        self::assertMatchesRegularExpression('/<input type="checkbox" name="marketing" value="1">/', $form, 'marketing desmarcado e opcional');
        self::assertStringNotContainsString('name="marketing" value="1" checked', $form);
    }

    public function testLegalPagesComeFromVersionedTemplates(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', LegalDocuments::PRIVACY);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', LegalDocuments::TERMS);
        self::assertSame('20/11/2026', LegalDocuments::label('2026-11-20'));
        $privacy = $this->file('container/web/default/v1/pages/legal/privacidade.php');
        foreach (['id="cookies"', 'id="direitos"', 'id="criancas"', 'id="prazos"', 'id="compartilhamento"', 'ANPD', 'art. 18', 'art. 14'] as $section) {
            self::assertStringContainsString($section, $privacy);
        }
        $identity = $this->file('container/web/default/v1/pages/legal/_identity.php');
        foreach (['CONF_SITE_CNPJ', 'CONF_PRIVACY_OFFICER', 'CONF_PRIVACY_EMAIL'] as $const) {
            self::assertStringContainsString($const, $identity);
        }
        self::assertStringContainsString('art. 101', $this->file('container/web/default/v1/pages/legal/termos.php'), 'foro do consumidor');
        self::assertFileDoesNotExist(self::root() . '/container/web/default/v1/pages/login/terms.php', 'texto antigo da Agência Moves');
        $footer = $this->file('container/web/default/v1/pages/footer.php');
        self::assertStringContainsString('CONF_SITE_CNPJ', $footer);
        self::assertStringContainsString('Encarregado de dados', $footer);
    }
}
