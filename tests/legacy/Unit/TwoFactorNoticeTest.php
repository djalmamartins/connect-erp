<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\TwoFactorNotice;
use Source\Support\MailTemplate;

/** Issue #7, risco 1: aviso por e-mail quando o 2FA muda (ADR 0028). */
final class TwoFactorNoticeTest extends TestCase
{
    public function testTemplateRendersEscapedNameChangeAndDate(): void
    {
        self::assertTrue((new MailTemplate('default'))->exists('two-factor-changed'));
        $html = (new TwoFactorNotice(new MailTemplate('default')))->render('<b>Ana</b>', TwoFactorNotice::ENABLED_AT_LOGIN, mktime(14, 5, 0, 11, 9, 2026));
        self::assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Ana</b>', $html);
        self::assertStringContainsString('ativada durante o login', $html);
        self::assertStringContainsString('09/11/2026 às 14:05', $html);
        self::assertStringContainsString('avise o administrador', $html);
    }

    public function testSendUsesInjectedSenderWithAccountEmail(): void
    {
        $calls = [];
        $notice = new TwoFactorNotice(new MailTemplate('default'), function (string $subject, string $html, string $email, string $name) use (&$calls): bool {
            $calls[] = [$subject, $email, $name, str_contains($html, 'redefinida por um administrador')];
            return true;
        });
        $user = (object)['id' => 7, 'first_name' => 'Ana', 'last_name' => 'Souza', 'email' => 'ana@connect.test'];
        self::assertTrue($notice->send($user, TwoFactorNotice::RESET_BY_ADMIN));
        self::assertSame([['Autenticação em dois fatores alterada', 'ana@connect.test', 'Ana Souza', true]], $calls);
    }

    public function testNoEmailOrUnknownEventOrSenderErrorNeverThrows(): void
    {
        $notice = new TwoFactorNotice(new MailTemplate('default'), fn (): bool => throw new \RuntimeException('smtp'));
        self::assertFalse($notice->send((object)['id' => 1, 'first_name' => 'Ana', 'email' => ''], TwoFactorNotice::ENABLED));
        self::assertFalse($notice->send((object)['id' => 1, 'first_name' => 'Ana', 'email' => 'ana@connect.test'], 'unknown'));
        self::assertFalse($notice->send((object)['id' => 1, 'first_name' => 'Ana', 'email' => 'ana@connect.test'], TwoFactorNotice::DISABLED));
    }

    public function testEveryTwoFactorChangeSendsANotice(): void
    {
        $root = __DIR__ . '/../../../source';
        $expect = [
            '/Models/Auth.php' => 'TwoFactorNotice::ENABLED_AT_LOGIN',
            '/Controllers/Api/V1/UserTwoFactor.php' => 'TwoFactorNotice::RESET_BY_ADMIN',
            '/Controllers/Erp/Connect/Security.php' => 'TwoFactorNotice::DISABLED',
            '/Controllers/Studio/TwoFactor.php' => 'TwoFactorNotice::ENABLED',
        ];
        foreach ($expect as $file => $needle) {
            self::assertStringContainsString($needle, (string)file_get_contents($root . $file), $file);
        }
        foreach (['/Controllers/Erp/Connect/Security.php', '/Controllers/Studio/TwoFactor.php'] as $file) {
            $code = (string)file_get_contents($root . $file);
            self::assertSame(substr_count($code, '->confirm('), substr_count($code, 'TwoFactorNotice::ENABLED)'), $file);
            self::assertSame(substr_count($code, '->disable('), substr_count($code, 'TwoFactorNotice::DISABLED)'), $file);
        }
    }
}
