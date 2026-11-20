<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\MailConfig;
use Source\Support\SecretBox;
use Source\Support\SystemSettingsRules;

/** ADR 0602: configuração geral no Studio, usuário/senha SMTP cifrados, AWS SES e e-mail de teste. */
final class SystemSettingsTest extends TestCase
{
    private function box(): SecretBox
    {
        return new SecretBox(base64_encode(str_repeat('k', 32)));
    }

    public function testSealedPasswordNeverStoredInPlainTextAndOpensBack(): void
    {
        $sealed = MailConfig::seal('Senha SES +/=<x>', 'mail_pass', $this->box());
        self::assertStringStartsWith(MailConfig::SEAL_PREFIX, $sealed);
        self::assertStringNotContainsString('Senha SES', $sealed);
        self::assertSame('Senha SES +/=<x>', MailConfig::open($sealed, 'mail_pass', $this->box()));
        self::assertNotSame($sealed, MailConfig::seal('Senha SES +/=<x>', 'mail_pass', $this->box()), 'Nonce novo a cada gravação.');
    }

    public function testLongestAllowedSecretFitsTheColumn(): void
    {
        $sealed = MailConfig::seal(str_repeat('a', MailConfig::SECRET_MAX_LENGTH), 'mail_pass', $this->box());
        self::assertLessThanOrEqual(255, strlen($sealed), 'Coluna mail_pass é varchar(255).');
    }

    public function testWrongKeyWrongColumnOrNoKeyDoNotOpen(): void
    {
        $sealed = MailConfig::seal('segredo', 'mail_pass', $this->box());
        self::assertNull(MailConfig::open($sealed, 'mail_pass', new SecretBox(base64_encode(str_repeat('z', 32)))));
        self::assertNull(MailConfig::open($sealed, 'mail_user', $this->box()), 'Valor da senha não abre como usuário.');
        self::assertNull(MailConfig::open($sealed, 'mail_pass', null));
        self::assertNull(MailConfig::open(MailConfig::SEAL_PREFIX . '%%%', 'mail_pass', $this->box()));
        self::assertSame('antiga', MailConfig::open('antiga', 'mail_pass', null), 'Valor legado sem cifra continua lido.');
    }

    public function testResolveOpensSealedSettingsAndEnvironmentStillWins(): void
    {
        $settings = ['mail_host' => 'email-smtp.sa-east-1.amazonaws.com', 'mail_user' => MailConfig::seal('AKIATESTE', 'mail_user', $this->box()),
            'mail_pass' => MailConfig::seal('senha-tela', 'mail_pass', $this->box()), 'mail_address' => 'nao-responda@connect.test'];
        $config = MailConfig::resolve(static fn (string $name): string|false => false, $settings, $this->box());
        self::assertSame('AKIATESTE', $config['user']);
        self::assertSame('senha-tela', $config['pass']);

        $env = MailConfig::resolve(static fn (string $name): string|false => $name === 'MOVESOS_SMTP_PASS' ? 'senha-env' : false, $settings, $this->box());
        self::assertSame('senha-env', $env['pass']);
        self::assertSame('env', $env['source']['pass']);

        $noKey = MailConfig::resolve(static fn (string $name): string|false => false, $settings);
        self::assertSame('', $noKey['pass']);
        self::assertSame('unreadable', $noKey['source']['pass']);
    }

    public function testMailStatusNeverLeaksCredentials(): void
    {
        $settings = ['mail_host' => 'email-smtp.sa-east-1.amazonaws.com', 'mail_user' => 'plain-user',
            'mail_pass' => MailConfig::seal('senha-tela', 'mail_pass', $this->box()), 'mail_address' => 'nao-responda@connect.test'];
        $config = MailConfig::resolve(static fn (string $name): string|false => $name === 'MOVESOS_SMTP_HOST' ? 'smtp.env' : false, $settings);
        $status = SystemSettingsRules::mailStatus($settings, $config, false);
        $json = (string)json_encode($status);
        self::assertStringNotContainsString('senha-tela', $json);
        self::assertStringNotContainsString('plain-user', $json);
        self::assertSame(['mail_user' => 'plain', 'mail_pass' => 'sealed'], $status['stored']);
        self::assertSame(['mail_pass'], $status['unreadable']);
        self::assertSame(['mail_host'], $status['from_env']);
        self::assertTrue($status['ready']);
        self::assertFalse($status['key_configured']);
    }

    public function testSesRegionsBuildTheSmtpHost(): void
    {
        self::assertSame('email-smtp.sa-east-1.amazonaws.com', SystemSettingsRules::sesHost('sa-east-1'));
        self::assertNull(SystemSettingsRules::sesHost('marte-1'));
        self::assertSame('ap-south-1', SystemSettingsRules::sesRegion('email-smtp.ap-south-1.amazonaws.com'));
        self::assertNull(SystemSettingsRules::sesRegion('smtp.gmail.com'));
    }

    public function testValidation(): void
    {
        self::assertNull(SystemSettingsRules::validate(['site_domain_ssl' => 'https://www.connectcondominios.com.br', 'site_domain' => 'connectcondominios.com.br',
            'mail_host' => 'email-smtp.sa-east-1.amazonaws.com', 'mail_port' => '587', 'mail_secure' => 'tls', 'mail_auth' => '1', 'mail_html' => '1',
            'mail_address' => 'nao-responda@connectcondominios.com.br', 'mail_suport' => '', 'mail_lang' => 'br']));
        foreach ([['site_domain_ssl' => 'http://x.com.br'], ['site_domain_ssl' => 'javascript:alert(1)'], ['site_domain' => 'https://x.com'],
            ['mail_host' => 'smtp.x.com:587'], ['mail_host' => 'a b'], ['mail_port' => '0'], ['mail_port' => '70000'], ['mail_port' => 'abc'],
            ['mail_secure' => 'starttls'], ['mail_auth' => '2'], ['mail_html' => 'sim'], ['mail_address' => 'sem-arroba'], ['mail_lang' => '<b>']] as $bad) {
            self::assertNotNull(SystemSettingsRules::validate($bad), (string)json_encode($bad));
        }
    }

    public function testScreenHasTestButtonAndNeverPrintsStoredSecrets(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 3) . '/container/cms/studio/v1/components/settings/home.php');
        self::assertStringContainsString('value="mail_test"', $view);
        self::assertStringContainsString('name="mail_test_to"', $view);
        self::assertStringContainsString('settings-mail-provider', $view);
        self::assertDoesNotMatchRegularExpression('~\$settings->mail_(user|pass)\s*\?\?~', $view, 'A tela não pode imprimir usuário ou senha guardados.');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Studio/Studio.php');
        self::assertStringContainsString('MailConfig::seal(', $controller);
        self::assertStringContainsString('"settings-mail-test|"', $controller);
        // A lista de temas procurava em container/apps/... e ficava vazia: a tela recusava qualquer gravação.
        self::assertStringNotContainsString('/container/apps/', $controller);
        self::assertStringContainsString('"/v1/default.php"', $controller);
        foreach (['container/web/default', 'container/app/connect', 'container/erp/connect'] as $theme) {
            self::assertFileExists(dirname(__DIR__, 3) . '/' . $theme . '/v1/default.php');
        }
    }
}
