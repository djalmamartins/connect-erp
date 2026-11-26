<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\MailSandbox;

/** ADR 0656: no lab com ERP_MAIL_DELIVERY=sandbox, a fila inteira vai só para a caixa de teste. */
final class MailSandboxTest extends TestCase
{
    public function testSandboxOnlyOutsideProductionWithValidBox(): void
    {
        self::assertSame('teste@connect.lab', MailSandbox::target('local', 'sandbox', ' teste@connect.lab '));
        self::assertSame('teste@connect.lab', MailSandbox::target('local', 'SANDBOX', 'teste@connect.lab'));
        self::assertNull(MailSandbox::target('production', 'sandbox', 'teste@connect.lab'), 'Produção nunca redireciona.');
        self::assertNull(MailSandbox::target('local', 'log', 'teste@connect.lab'));
        self::assertNull(MailSandbox::target('local', '', 'teste@connect.lab'), 'Chave com nome errado = vazia.');
        self::assertNull(MailSandbox::target('local', 'sandbox', 'nao-e-email'));
        self::assertNull(MailSandbox::target('local', 'sandbox', null));
    }

    public function testSubjectGetsPrefixOnce(): void
    {
        self::assertSame('[TESTE] Seu boleto', MailSandbox::subject('Seu boleto'));
        self::assertSame('[TESTE] Seu boleto', MailSandbox::subject('[TESTE] Seu boleto'));
    }

    public function testTyposPointToTheRightKey(): void
    {
        self::assertSame(['ERP_MAIL_ELIVERY' => 'ERP_MAIL_DELIVERY'],
            MailSandbox::typos(['APP_ENV', 'ERP_MAIL_ELIVERY', 'ERP_MAIL_SANDBOX_TO', 'LAB_MAIL_QUEUE_SEND', 'DB_HOST', 'TALK_KEY']));
        self::assertSame(['LAB_MAIL_QUEU_SEND' => 'LAB_MAIL_QUEUE_SEND'], MailSandbox::typos(['lab_mail_queu_send']));
        self::assertSame([], MailSandbox::typos(['MOVESOS_SMTP_HOST', 'CONNECT_2FA_KEY', 'LEGACY_DB_PASSWORD']));
    }

    public function testMask(): void
    {
        self::assertSame('d******@connectcondominios.com.br', MailSandbox::mask('djalma.martins@connectcondominios.com.br'));
    }

    public function testQueueUsesSandboxAndMailerIsClearedOnFailure(): void
    {
        $email = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Support/Email.php');
        self::assertMatchesRegularExpression('/function send\(.*?MailSandbox::current\(\).*?addAddress\(\$sandboxTo \?\?/s', $email,
            'O envio direto (recuperação de senha) também vai para a caixa de teste.');
        self::assertMatchesRegularExpression('/finally \{[^}]*clearAllRecipients\(\)/s', $email,
            'Destinatário de um envio que falhou não pode seguir no próximo e-mail da fila.');
    }
}
