<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** Teste de restauração (ADR 0334): as travas recusam o banco de produção e nomes sem "restore", antes de tocar no banco. */
final class RestoreCheckGuardTest extends TestCase
{
    /** @return array{0:int, 1:string} */
    private function runScript(string $target): array
    {
        $root = dirname(__DIR__, 3);
        $env = ['PATH' => (string)getenv('PATH'), 'MOVESOS_ENV' => 'testing', 'MOVESOS_TEST_DB' => 'movesos_test', 'BACKUP_RESTORE_DB' => $target,
            'MOVESOS_TEST_DB_USER' => 'ninguem', 'MOVESOS_TEST_DB_PASS' => 'x', 'HOME' => sys_get_temp_dir()];
        $proc = proc_open(['bash', $root . '/tools/hosting/restore-check.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string)$out];
    }

    public function testRefusesProductionDatabase(): void
    {
        [$code, $out] = $this->runScript('movesos_test');
        self::assertSame(2, $code);
        self::assertStringContainsString('Recusado: BACKUP_RESTORE_DB é o banco de produção', $out);
    }

    public function testRefusesNameWithoutRestoreAndInvalidNames(): void
    {
        [$code, $out] = $this->runScript('outro_banco');
        self::assertSame(2, $code);
        self::assertStringContainsString('precisa ter "restore" no nome', $out);
        [$code, $out] = $this->runScript('x_restore`; DROP');
        self::assertSame(2, $code);
        self::assertStringContainsString('nome de banco inválido', $out);
    }

    public function testAcceptsNameCutByHpanel(): void
    {
        // O hPanel cortou "connect_restore" para "connect_restor" (ADR 0651): a trava do nome deixa passar.
        [$code, $out] = $this->runScript('u807812373_connect_restor');
        self::assertNotSame(2, $code);
        self::assertStringNotContainsString('precisa ter "restore" no nome', $out);
    }

    public function testRequiresTargetDatabase(): void
    {
        [$code, $out] = $this->runScript('');
        self::assertSame(2, $code);
        self::assertStringContainsString('Defina BACKUP_RESTORE_DB', $out);
    }
}
