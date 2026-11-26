<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\DeployReadiness;

/**
 * ADR 0357: a hospedagem só tem o .env (sem Docker passando variáveis), e o Config.php só copia do .env as chaves da lista
 * dele. Toda chave que o sistema lê com getenv precisa estar nessa lista, e o modelo .env.production.example precisa ter
 * tudo o que o deploy-check pede.
 */
final class EnvironmentKeysTest extends TestCase
{
    /** Chaves que só vêm do processo (testes, build), nunca do .env. */
    private const PROCESS_ONLY = ['MOVESOS_TEST_DB_DISABLED', 'MOVESOS_BUILD_ASSETS'];
    /** Lidas pelos scripts de shell da hospedagem direto do .env (tools/hosting/*.sh). */
    private const SHELL_ONLY = ['BACKUP_RCLONE_REMOTE', 'BACKUP_KEEP_DAYS', 'BACKUP_KEEP_LOCAL', 'BACKUP_RESTORE_DB', 'BACKUP_RESTORE_USER', 'BACKUP_RESTORE_PASS', 'RCLONE_BIN'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private static function allowed(): array
    {
        $config = (string)file_get_contents(self::root() . '/source/Boot/Config.php');
        preg_match('~allowedEnvironmentKeys = \[(.*?)\];~s', $config, $list);
        preg_match('~environmentKeys = \[(.*?)\];~s', $config, $aliases);
        preg_match_all('~"([A-Z0-9_]+)"~', ($list[1] ?? '') . ($aliases[1] ?? ''), $keys);
        return array_values(array_unique($keys[1]));
    }

    /** @return array<string, string> */
    private static function template(): array
    {
        $out = [];
        foreach (file(self::root() . '/.env.production.example', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('~^([A-Z0-9_]+)=(.*)$~', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    public function testEveryKeyTheSystemReadsComesFromTheEnvFile(): void
    {
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/source', \FilesystemIterator::SKIP_DOTS));
        $paths = [self::root() . '/index.php'];
        foreach ($files as $file) {
            $paths[] = (string)$file;
        }
        foreach (['service', 'container'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with((string)$file, '.php')) {
                    $paths[] = (string)$file;
                }
            }
        }
        foreach ($paths as $path) {
            if (str_ends_with($path, '.php') && preg_match_all('~getenv\(([\'"])([A-Z0-9_]+)\1\)~', (string)file_get_contents($path), $m)) {
                $used = array_merge($used, $m[2]);
            }
        }
        $used = array_values(array_unique($used));
        self::assertContains('CONNECT_2FA_KEY', $used, 'a busca por getenv deixou de achar as chaves conhecidas');
        $missing = array_values(array_diff($used, self::allowed(), self::PROCESS_ONLY));
        self::assertSame([], $missing, 'Chave lida com getenv fora da lista do Config.php: na hospedagem ela nunca chega.');
    }

    public function testProductionTemplateHasWhatTheDeployCheckAsks(): void
    {
        $template = self::template();
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'CONNECT_2FA_KEY', 'CONNECT_2FA_ENFORCE_FROM',
            'CONNECT_BANK_VAULT_KEY', 'ERP_DEMO_DATA', 'ERP_MAIL_DELIVERY', 'MOVESOS_SMTP_HOST', 'ALERT_EMAIL'] as $key) {
            self::assertArrayHasKey($key, $template, $key);
        }
        $unknown = array_values(array_diff(array_keys($template), self::allowed(), self::SHELL_ONLY));
        self::assertSame([], $unknown, 'Chave no modelo que o sistema não lê do .env.');
        self::assertSame('production', $template['APP_ENV']);
        // O Talk roda no Raspberry Pi do escritório, fora da hospedagem (ADR 0359): nenhuma chave TALK_/EVOLUTION_ ativa.
        self::assertSame([], preg_grep('~^(TALK|EVOLUTION)_~', array_keys($template)), 'Talk não roda na hospedagem (ADR 0359)');

        // Preenchido só com os marcadores <...>, o deploy-check acusa as chaves a gerar, nunca debug, demonstração ou endereço.
        $errors = DeployReadiness::env($template, time())['errors'];
        self::assertCount(2, $errors);
        self::assertStringContainsString('CONNECT_2FA_KEY', $errors[0]);
        self::assertStringContainsString('CONNECT_BANK_VAULT_KEY', $errors[1]);
        foreach (['CONNECT_2FA_KEY', 'CONNECT_BANK_VAULT_KEY', 'DB_PASS', 'MOVESOS_SMTP_PASS'] as $secret) {
            self::assertMatchesRegularExpression('~^<[^>]+>$~', $template[$secret], 'segredo sem valor no Git: ' . $secret);
        }
    }

    public function testHostingGuideUsesTheTemplate(): void
    {
        self::assertStringContainsString('.env.production.example', (string)file_get_contents(self::root() . '/docs/process/HOSPEDAGEM.md'));
        self::assertStringContainsString('!.env.production.example', (string)file_get_contents(self::root() . '/.gitignore'));
    }
}
