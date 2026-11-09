<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\MailTemplate;

/** A4: templates de e-mail no ambiente send (container/send/{tema}/v1). */
final class MailTemplateTest extends TestCase
{
    public function testMailAndSendResolveToSendContainer(): void
    {
        foreach ([['mail', 'default'], ['mail', 'mail'], ['send', 'default'], ['send', 'SEND']] as [$area, $theme]) {
            self::assertStringEndsWith('/container/send/default/v1', moves_container_path($area, $theme));
        }
        self::assertStringEndsWith('/container/send/default/v1/images/x.png', moves_container_url('mail', 'default', '/images/x.png'));
        self::assertStringEndsWith('/container/send/default/v1', moves_container_path('send', '../../source'), 'Tema inválido não sai de container/send.');
    }

    public function testThemeRootFollowsContract(): void
    {
        $root = moves_container_path('send', 'default');
        $php = array_map('basename', glob($root . '/*.php') ?: []);
        self::assertSame(['default.php'], $php, 'A raiz do tema só tem a moldura; send não tem rotas.');
        foreach (['forget', 'confirm', 'two-factor-changed'] as $template) {
            self::assertTrue((new MailTemplate('default'))->exists($template), "Template {$template} ausente.");
        }
    }

    public function testForgetRendersEscapedNameAndLinkInsideFrame(): void
    {
        $html = (new MailTemplate('default'))->render('forget', [
            'first_name' => '<b>Ana</b>',
            'forget_link' => 'https://connect.test/forget/abc:ana@connect.test?x="1"',
            'site_name' => 'Connect Teste',
        ]);

        self::assertStringStartsWith('<!doctype html>', ltrim($html));
        self::assertStringContainsString('Recuperação de senha', $html);
        self::assertStringContainsString('Connect Teste', $html);
        self::assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Ana</b>', $html);
        self::assertStringContainsString('href="https://connect.test/forget/abc:ana@connect.test?x=&quot;1&quot;"', $html);
    }

    public function testConfirmRenders(): void
    {
        $html = (new MailTemplate('default'))->render('confirm', ['first_name' => 'Bia', 'confirm_link' => 'https://connect.test/confirm/x']);
        self::assertStringContainsString('Confirme seu cadastro', $html);
        self::assertStringContainsString('https://connect.test/confirm/x', $html);
    }

    public function testRejectsUnknownAndUnsafeTemplateNames(): void
    {
        $mail = new MailTemplate('default');
        self::assertFalse($mail->exists('../default'));
        self::assertFalse($mail->exists('nao-existe'));

        try {
            $mail->render('../../../index');
            self::fail('Nome com travessia de diretório foi aceito.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\RuntimeException::class);
        $mail->render('nao-existe');
    }
}
