<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\MailConfig;

/** ADR 0060: credenciais SMTP do ambiente valem sobre a tabela settings. */
final class MailConfigTest extends TestCase
{
    private const SETTINGS = [
        'mail_host' => 'smtp.legado', 'mail_port' => '465', 'mail_user' => 'legado@x', 'mail_pass' => 'senha-do-banco',
        'mail_secure' => 'ssl', 'mail_auth' => '1', 'mail_address' => 'no-reply@legado', 'mail_name' => 'Legado',
    ];

    public function testEnvironmentWinsOverSettings(): void
    {
        $env = ['MOVESOS_SMTP_HOST' => 'smtp.env', 'MOVESOS_SMTP_PASS' => 'senha-do-ambiente', 'MOVESOS_SMTP_AUTH' => 'false'];
        $config = MailConfig::resolve(static fn (string $name): string|false => $env[$name] ?? false, self::SETTINGS);

        self::assertSame('smtp.env', $config['host']);
        self::assertSame('senha-do-ambiente', $config['pass']);
        self::assertFalse($config['auth']);
        self::assertSame('legado@x', $config['user'], 'Campo sem variável cai no valor legado.');
        self::assertSame('465', $config['port']);
        self::assertSame(['host' => 'env', 'pass' => 'env', 'auth' => 'env'], array_intersect($config['source'], ['env']));
    }

    public function testEmptyVariableFallsBackAndObjectSettingsWork(): void
    {
        $settings = (object)self::SETTINGS;
        $config = MailConfig::resolve(static fn (string $name): string|false => $name === 'MOVESOS_SMTP_PASS' ? '' : false, $settings);
        self::assertSame('senha-do-banco', $config['pass']);
        self::assertSame('settings', $config['source']['pass']);
        self::assertSame('Legado', $config['from_name']);
    }

    public function testMissingEverywhereIsEmptyString(): void
    {
        $config = MailConfig::resolve(static fn (string $name): string|false => false, []);
        self::assertSame('', $config['host']);
        self::assertSame('', $config['pass']);
    }
}
