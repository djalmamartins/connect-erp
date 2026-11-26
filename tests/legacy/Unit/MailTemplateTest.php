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

    /** ADR 0397/0646: moldura Connect branca e dourada (logo dourado sobre branco, rodapé com identificação e privacidade). */
    public function testFrameCarriesConnectIdentityAndLegalFooter(): void
    {
        $html = (new MailTemplate('default'))->render('forget', [
            'first_name' => 'Ana', 'forget_link' => 'https://connect.test/f/1',
            'site_name' => 'Connect Condomínios', 'site_url' => 'https://www.connect.test',
            'logo_url' => 'https://www.connect.test/container/send/default/v1/assets/images/logo-connect.png',
            'privacy_url' => 'https://www.connect.test/politica-de-privacidade',
            'company_cnpj' => '12.345.678/0001-90', 'company_address' => 'Rua A, 10, Centro, Cidade - UF',
            'company_phone' => '(11) 4000-0000', 'company_email' => 'contato@connect.test',
        ]);
        self::assertStringContainsString('padding:32px 28px 8px;background:#ffffff;', $html, 'Cabeçalho branco com o logo dourado (ADR 0646).');
        self::assertStringNotContainsString('solid #c5a131', $html, 'Sem bordas ou linhas douradas (ADR 0646).');
        self::assertStringContainsString('background:#f6faf8;font-size:12px', $html, 'Rodapé verde bem claro (ADR 0646).');
        self::assertStringContainsString('alt="Connect Condomínios"', $html, 'Logo com texto alternativo.');
        self::assertStringContainsString('Inter,', $html);
        self::assertStringContainsString('CNPJ 12.345.678/0001-90', $html);
        self::assertStringContainsString('Rua A, 10, Centro, Cidade - UF', $html);
        self::assertStringContainsString('href="https://www.connect.test/politica-de-privacidade"', $html);
        self::assertStringContainsString('background:#07884c', $html, 'Botão verde (ADR 0646).');
        self::assertStringNotContainsString('#0f5fa8', $html);
        self::assertStringNotContainsString('Descadastrar', $html, 'Sem link de descadastro quando o envio não informa.');

        $withLink = (new MailTemplate('default'))->render('announcement', [
            'first_name' => 'Ana', 'subject' => 'Aviso', 'body' => 'Texto', 'condominium' => 'Cond',
            'unsubscribe_url' => 'https://www.connect.test/descadastrar#t',
        ]);
        self::assertStringContainsString('href="https://www.connect.test/descadastrar#t"', $withLink);
    }

    public function testEveryTemplateUsesTheFrameWithoutOldColors(): void
    {
        $root = moves_container_path('send', 'default');
        // ADR 0646: sem o cabeçalho verde-escuro e sem bordas douradas em nenhuma parte da moldura, partes ou modelos.
        $files = array_merge([$root . '/default.php'], glob($root . '/partials/*.php') ?: [], glob($root . '/pages/*.php') ?: []);
        foreach ($files as $file) {
            $source = strtolower((string)file_get_contents($file));
            self::assertStringNotContainsString('#0d2e27', $source, basename($file) . ' ainda usa o verde-escuro do cabeçalho antigo.');
            self::assertStringNotContainsString('solid #c5a131', $source, basename($file) . ' com borda dourada.');
        }
        foreach (glob($root . '/pages/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            self::assertStringContainsString("\$this->layout('default'", $source, basename($file) . ' fora da moldura.');
            self::assertStringNotContainsString('#0f5fa8', $source, basename($file) . ' com o botão azul antigo.');
        }
    }

    /** Aviso genérico (Help Desk, agenda, propostas): todo texto escapado, inclusive citação e quadro. */
    public function testNoticeEscapesEveryField(): void
    {
        $html = (new MailTemplate('default'))->render('notice', [
            'subject' => 'Chamado <b>1</b>', 'first_name' => '<i>Ana</i>',
            'paragraphs' => ['Linha <script>x</script>'], 'quote' => "um\n<img src=x>",
            'facts' => [['Prioridade', '<u>Alta</u>']], 'highlight' => [['Protocolo', '<s>P-1</s>']],
            'button_label' => 'Abrir', 'button_link' => 'https://connect.test/c?a="1"', 'note' => '<em>n</em>',
        ]);
        foreach (['<b>1</b>', '<i>Ana</i>', '<script>', '<img src=x>', '<u>Alta</u>', '<s>P-1</s>', '<em>n</em>'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;i&gt;Ana&lt;/i&gt;', $html);
        self::assertStringContainsString('um<br />', $html);
        self::assertStringContainsString('href="https://connect.test/c?a=&quot;1&quot;"', $html);
    }
}
