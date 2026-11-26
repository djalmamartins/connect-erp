<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0361: tools/hosting/update.sh atualiza a versão na hospedagem. Roda o script de verdade num repositório Git
 * temporário, com backup, php e composer falsos que só anotam o que seria executado.
 */
final class HostingUpdateScriptTest extends TestCase
{
    private string $dir;
    private string $old;
    private string $new;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/update-sh-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tools/hosting', 0777, true);
        mkdir($this->dir . '/bin', 0777, true);
        copy(dirname(__DIR__, 3) . '/tools/hosting/update.sh', $this->dir . '/tools/hosting/update.sh');
        file_put_contents($this->dir . '/tools/hosting/backup.sh', "#!/bin/sh\necho backup >> \"\$(dirname \"\$0\")/../../calls\"\n");
        file_put_contents($this->dir . '/.gitignore', "calls\nbin/\n.local/\nstorage/\n");
        // php falso: o deploy-check falha com FAIL_CHECK=1, a conferência por HTTP com FAIL_SMOKE=1.
        file_put_contents($this->dir . '/bin/php', "#!/bin/sh\necho \"php \$*\" >> calls\n"
            . "case \"\$1\" in *deploy-check*) [ \"\${FAIL_CHECK:-0}\" = 1 ] && exit 1 ;; *smoke-http*) [ \"\${FAIL_SMOKE:-0}\" = 1 ] && exit 1 ;; esac\nexit 0\n");
        file_put_contents($this->dir . '/bin/composer', "#!/bin/sh\necho \"composer \$* @\$(git rev-parse --short HEAD)\" >> calls\n"
            . "[ \"\$1\" = db:migrate ] && [ \"\${FAIL_MIGRATE:-0}\" = 1 ] && exit 1\nexit 0\n");
        chmod($this->dir . '/bin/php', 0755);
        chmod($this->dir . '/bin/composer', 0755);
        $this->git('init -q .');
        file_put_contents($this->dir . '/VERSION', "v1\n");
        $this->git('add -A');
        $this->git('commit -q -m v1');
        $this->old = $this->head();
        file_put_contents($this->dir . '/VERSION', "v2\n");
        $this->git('commit -q -am v2');
        $this->new = $this->head();
        $this->git('checkout -q --detach ' . $this->old);
    }

    private function git(string $args): void
    {
        exec('cd ' . escapeshellarg($this->dir) . ' && git -c user.name=t -c user.email=t@example.invalid ' . $args . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, string> $env @return array{int, string, string} */
    private function update(array $args, array $env = []): array
    {
        @unlink($this->dir . '/calls');
        $cmd = 'cd ' . escapeshellarg($this->dir) . ' && env PHP_BIN=' . escapeshellarg($this->dir . '/bin/php')
            . ' COMPOSER_BIN=' . escapeshellarg($this->dir . '/bin/composer');
        foreach ($env as $key => $value) {
            $cmd .= ' ' . $key . '=' . escapeshellarg($value);
        }
        $cmd .= ' bash tools/hosting/update.sh ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out), (string)@file_get_contents($this->dir . '/calls')];
    }

    private function head(): string
    {
        return trim((string)shell_exec('cd ' . escapeshellarg($this->dir) . ' && git rev-parse HEAD'));
    }

    public function testHappyPathBacksUpChecksMigratesAndSmokes(): void
    {
        [$code, $out, $calls] = $this->update([$this->new, '--domain=example.invalid']);
        self::assertSame(0, $code, $out);
        self::assertSame($this->new, $this->head());
        $short = substr($this->new, 0, 7);
        $order = ['backup', "composer install --no-dev --optimize-autoloader @$short", 'php service/commands/deploy-check.php',
            "composer db:migrate @$short", 'php tools/help.php sync', 'php tools/hosting/smoke-http.php --domain=example.invalid'];
        self::assertSame($order, explode("\n", trim($calls)));
        self::assertSame($this->old, trim((string)file_get_contents($this->dir . '/.local/deploy/previous')));
        self::assertStringContainsString('OK: versão ' . $this->new, (string)file_get_contents($this->dir . '/storage/logs/deploy.log'));
    }

    public function testFailedDeployCheckGoesBackWithoutTouchingTheDatabase(): void
    {
        [$code, $out, $calls] = $this->update([$this->new], ['FAIL_CHECK' => '1']);
        self::assertSame(1, $code, $out);
        self::assertSame($this->old, $this->head(), 'voltou para o commit anterior');
        self::assertStringNotContainsString('db:migrate', $calls);
        self::assertStringContainsString('composer install --no-dev --optimize-autoloader @' . substr($this->old, 0, 7), $calls,
            'reinstala as dependências do commit anterior');
        self::assertStringContainsString('VOLTOU para ' . $this->old, $out);
    }

    public function testFailedMigrationStopsAndExplainsTheWayBack(): void
    {
        [$code, $out, $calls] = $this->update([$this->new], ['FAIL_MIGRATE' => '1']);
        self::assertSame(2, $code, $out);
        self::assertSame($this->new, $this->head(), 'não volta o código sozinho com o banco mexido');
        self::assertStringContainsString('bash tools/hosting/update.sh ' . $this->old, $out);
        self::assertStringNotContainsString('smoke-http', $calls);
    }

    public function testFailedSmokeExitsWithThree(): void
    {
        [$code, $out] = $this->update([$this->new, '--domain=example.invalid'], ['FAIL_SMOKE' => '1']);
        self::assertSame(3, $code, $out);
        self::assertStringContainsString('bash tools/hosting/update.sh ' . $this->old, $out);
    }

    public function testRefusesDirtyTreeAndUnknownCommitBeforeChangingAnything(): void
    {
        file_put_contents($this->dir . '/VERSION', "editado no servidor\n");
        [$code, , $calls] = $this->update([$this->new]);
        self::assertSame(4, $code);
        self::assertSame('', $calls, 'nem o backup roda');
        $this->git('checkout -q -- VERSION');

        [$code, $out] = $this->update(['naoexiste123']);
        self::assertSame(6, $code, $out);
        self::assertSame($this->old, $this->head());

        [$code] = $this->update([]);
        self::assertSame(64, $code);
    }

    public function testHostingGuideUsesTheScript(): void
    {
        self::assertStringContainsString('bash tools/hosting/update.sh', (string)file_get_contents(dirname(__DIR__, 3) . '/docs/process/HOSPEDAGEM.md'));
    }
}
