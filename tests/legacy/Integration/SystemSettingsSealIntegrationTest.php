<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Settings\Settings;
use Source\Support\MailConfig;
use Source\Support\SecretBox;

/** ADR 0600: senha SMTP da tela gravada cifrada na linha de settings e lida de volta pelo MailConfig. */
final class SystemSettingsSealIntegrationTest extends TestCase
{
    public function testSealedCredentialsRoundTripThroughTheSettingsRow(): void
    {
        $box = new SecretBox(base64_encode(random_bytes(32)));
        $settings = Settings::dados();
        $settings->mail_host = 'email-smtp.sa-east-1.amazonaws.com';
        $settings->mail_user = MailConfig::seal('AKIAINTEGRACAO', 'mail_user', $box);
        $settings->mail_pass = MailConfig::seal('senha-smtp-de-teste', 'mail_pass', $box);
        self::assertTrue($settings->save());

        $raw = $this->pdo->query('SELECT mail_user, mail_pass FROM settings LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertStringStartsWith(MailConfig::SEAL_PREFIX, (string)$raw['mail_pass']);
        self::assertStringNotContainsString('senha-smtp-de-teste', (string)$raw['mail_pass']);
        self::assertStringNotContainsString('AKIAINTEGRACAO', (string)$raw['mail_user']);

        $config = MailConfig::resolve(static fn (string $name): string|false => false, Settings::dados(), $box);
        self::assertSame('AKIAINTEGRACAO', $config['user']);
        self::assertSame('senha-smtp-de-teste', $config['pass']);
        self::assertSame('email-smtp.sa-east-1.amazonaws.com', $config['host']);
    }
}
