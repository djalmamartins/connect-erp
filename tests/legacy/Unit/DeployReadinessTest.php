<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Support\DeployReadiness;

/** ADR 0354: conferência do .env e do banco de produção no deploy-check. */
final class DeployReadinessTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @return array<string, string> */
    private static function good(): array
    {
        return [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://erp.connectcondominios.com.br', 'ERP_DEMO_DATA' => 'false',
            'CONNECT_2FA_KEY' => base64_encode(str_repeat('a', 32)), 'CONNECT_BANK_VAULT_KEY' => base64_encode(str_repeat('b', 32)),
            'TALK_KEY' => base64_encode(str_repeat('c', 32)), 'CONNECT_2FA_ENFORCE_FROM' => '2026-12-01', 'ALERT_EMAIL' => 'alerta@connectcondominios.com.br',
            'ERP_MAIL_DELIVERY' => 'production', 'MOVESOS_SMTP_HOST' => 'smtp.hostinger.com',
        ];
    }

    public function testGoodProductionEnvHasNothing(): void
    {
        self::assertSame(['errors' => [], 'warnings' => []], DeployReadiness::env(self::good(), self::NOW));
    }

    public function testUnsafeEnvIsAnError(): void
    {
        $bad = ['APP_DEBUG' => 'true', 'ERP_DEMO_DATA' => '1', 'APP_URL' => 'https://erp.connect.lab', 'CONNECT_2FA_KEY' => 'curta'] + self::good();
        $errors = DeployReadiness::env($bad, self::NOW)['errors'];
        self::assertCount(4, $errors);
        self::assertStringContainsString('APP_DEBUG', $errors[0]);
        self::assertStringContainsString('ERP_DEMO_DATA', $errors[1]);
        self::assertStringContainsString('APP_URL', $errors[2]);
        self::assertStringContainsString('CONNECT_2FA_KEY', $errors[3]);

        $same = ['CONNECT_BANK_VAULT_KEY' => self::good()['CONNECT_2FA_KEY'], 'TALK_KEY' => self::good()['CONNECT_2FA_KEY']] + self::good();
        self::assertCount(2, DeployReadiness::env($same, self::NOW)['errors'], 'chaves repetidas');
        foreach (['http://127.0.0.1:8099', 'https://localhost', '', 'https://app.exemplo.test'] as $url) {
            self::assertNotSame([], DeployReadiness::env(['APP_URL' => $url] + self::good(), self::NOW)['errors'], $url);
        }
        self::assertNotSame([], DeployReadiness::env(array_diff_key(self::good(), ['CONNECT_BANK_VAULT_KEY' => 1]), self::NOW)['errors']);
    }

    public function testMissingRoutineItemsAreWarnings(): void
    {
        $env = ['CONNECT_2FA_ENFORCE_FROM' => '2030-01-01', 'ALERT_EMAIL' => '', 'ERP_MAIL_DELIVERY' => 'log', 'MOVESOS_SMTP_HOST' => ''] + self::good();
        $out = DeployReadiness::env($env, self::NOW);
        self::assertSame([], $out['errors']);
        self::assertCount(4, $out['warnings']);
        self::assertStringContainsString('2FA', $out['warnings'][0]);
    }

    public function testDatabaseFindsTestAccountsStaffWithout2faAndSandboxBanks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::assertSame(['errors' => [], 'warnings' => []], DeployReadiness::database($pdo), 'sem tabelas (antes da migration) não quebra');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE user_two_factor (user_id INTEGER PRIMARY KEY, confirmed_at TEXT)');
        $pdo->exec('CREATE TABLE erp_bank_credentials (id INTEGER PRIMARY KEY, environment TEXT)');
        $pdo->exec("INSERT INTO users (id, email, status) VALUES (1, 'djalma@connectcondominios.com.br', 'confirmed'), (2, 'morador.teste@connect.lab', 'confirmed'),
            (3, 'ana@exemplo.test', 'confirmed'), (4, 'joao@gmail.com', 'confirmed'), (5, 'x@labirinto.com.br', 'confirmed')");
        $pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (1, 1), (4, 2), (4, 3)');
        $pdo->exec("INSERT INTO user_two_factor (user_id, confirmed_at) VALUES (1, '2026-11-17 10:00:00'), (4, NULL)");
        $pdo->exec("INSERT INTO erp_bank_credentials (id, environment) VALUES (1, 'production'), (2, 'sandbox')");

        $out = DeployReadiness::database($pdo);
        self::assertSame(['2 conta(s) de teste ativa(s) no banco (ids 2, 3). Exclua ou troque o e-mail antes de abrir.'], $out['errors']);
        self::assertCount(2, $out['warnings']);
        self::assertStringContainsString('1 pessoa(s) da equipe sem 2FA confirmado (ids 4)', $out['warnings'][0]);
        self::assertStringContainsString('ids 2', $out['warnings'][1]);
        self::assertStringNotContainsString('@', implode(' ', array_merge($out['errors'], $out['warnings'])), 'sem e-mail na saída');
    }

    public function testDeployCheckCallsIt(): void
    {
        $check = (string)file_get_contents(dirname(__DIR__, 3) . '/service/commands/deploy-check.php');
        self::assertStringContainsString('DeployReadiness::env(', $check);
        self::assertStringContainsString('DeployReadiness::database(', $check);
        self::assertStringContainsString('DeployReadiness::php(', $check);
    }

    public function testPhpLimitsBelowWhatTheSystemAcceptsAreWarnings(): void
    {
        $good = ['memory_limit' => '512M', 'upload_max_filesize' => '64M', 'post_max_size' => '70M', 'file_uploads' => '1'];
        self::assertSame([], DeployReadiness::php($good));
        self::assertSame([], DeployReadiness::php(['memory_limit' => '-1', 'post_max_size' => '0', 'upload_max_filesize' => '1G'] + $good));

        $warnings = DeployReadiness::php(['memory_limit' => '128M', 'upload_max_filesize' => '2M', 'post_max_size' => '8M', 'file_uploads' => 'Off']);
        self::assertCount(4, $warnings);
        foreach (['memory_limit', 'file_uploads', 'upload_max_filesize', 'post_max_size'] as $i => $directive) {
            self::assertStringStartsWith($directive, $warnings[$i]);
            self::assertStringContainsString('hPanel', $warnings[$i]);
        }
        $post = DeployReadiness::php(['upload_max_filesize' => '100M', 'post_max_size' => '64M'] + $good);
        self::assertCount(1, $post, 'post_max_size menor que o upload');
        self::assertStringStartsWith('post_max_size', $post[0]);
    }

    public function testBytesParsesPhpShorthand(): void
    {
        self::assertSame(268435456, DeployReadiness::bytes('256M'));
        self::assertSame(2048, DeployReadiness::bytes('2k'));
        self::assertSame(1073741824, DeployReadiness::bytes(' 1G '));
        self::assertSame(-1, DeployReadiness::bytes('-1'));
        self::assertSame(0, DeployReadiness::bytes(false));
        self::assertSame(0, DeployReadiness::bytes('lixo'));
    }

    public function testRequiredUploadCoversEveryUploadLimitInTheCode(): void
    {
        $limits = [\Source\Services\Erp\VirtualDisk::MAX_BYTES, \Source\Services\Inspections\PhotoStorage::MAX_BYTES,
            \Source\Services\Resident\ResidentProfile::MAX_BYTES];
        foreach ((new \ReflectionClass(\Source\Support\Upload::class))->getConstants() as $name => $value) {
            if (str_ends_with($name, '_MAX_BYTES')) {
                $limits[] = $value;
            }
        }
        self::assertGreaterThanOrEqual(max($limits), DeployReadiness::REQUIRED_UPLOAD_BYTES,
            'um limite de envio do sistema passou do que o deploy-check pede ao PHP');
    }
}
