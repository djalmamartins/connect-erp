<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\QrCode;

/**
 * QR code do 2FA gerado no servidor (ADR 0029): o link otpauth:// tem o segredo TOTP e não sai do servidor.
 */
final class QrCodeTest extends TestCase
{
    private const URI = 'otpauth://totp/Connect:ana%40example.invalid?secret=JBSWY3DPEHPK3PXP&issuer=Connect';

    public function testGeneratesInlineAccessibleSvg(): void
    {
        $svg = QrCode::svg(self::URI, 196, 'QR code "2FA"');

        self::assertNotNull($svg);
        self::assertStringStartsWith('<svg role="img" aria-label="QR code &quot;2FA&quot;" focusable="false"', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringContainsString('width="196"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    public function testSecretNeverAppearsAsTextNorGoesToExternalServices(): void
    {
        $svg = (string)QrCode::svg(self::URI);

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $svg);
        self::assertStringNotContainsString('otpauth', $svg);
        self::assertSame(0, preg_match('~(?:href|src)=~i', $svg), 'o SVG não referencia recursos externos');
    }

    public function testSameContentGivesSameCodeAndEmptyGivesNull(): void
    {
        self::assertSame(QrCode::svg(self::URI), QrCode::svg(self::URI));
        self::assertNotSame(QrCode::svg(self::URI), QrCode::svg(self::URI . 'X'));
        self::assertNull(QrCode::svg(''));
    }

    public function testSetupScreensShowTheQrCodeWhenAvailable(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            'container/cms/studio/v1/components/login/two-factor.php' => "\$setup['qr']",
            'container/cms/studio/v1/components/login/two-factor-manage.php' => "\$setup['qr']",
            'container/erp/connect/v1/pages/auth/security.php' => "\$twoFactorSetup['qr']",
        ] as $view => $key) {
            $source = (string)file_get_contents($root . '/' . $view);
            self::assertStringContainsString('!empty(' . $key . ')', $source, "$view mostra o QR só quando ele existe");
        }
        foreach (['source/Controllers/Studio/TwoFactor.php' => 2, 'source/Controllers/Erp/Connect/Security.php' => 1] as $controller => $count) {
            self::assertSame($count, substr_count((string)file_get_contents($root . '/' . $controller), 'QrCode::svg('), $controller);
        }
    }
}
