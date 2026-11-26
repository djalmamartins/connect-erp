<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** ADR 0651: o banco de teste da restauração pode ter usuário próprio (o hPanel cria um usuário por banco). */
final class DbClientConfigRestoreTest extends TestCase
{
    /** @param array<string, string> $extra */
    private function runConfig(string $mode, array $extra = []): string
    {
        $root = dirname(__DIR__, 3);
        $env = ['PATH' => (string)getenv('PATH'), 'MOVESOS_ENV' => 'testing', 'MOVESOS_TEST_DB' => 'movesos_test',
            'MOVESOS_TEST_DB_USER' => 'sistema', 'MOVESOS_TEST_DB_PASS' => 'senha-sistema', 'HOME' => sys_get_temp_dir()] + $extra;
        $args = ['php', $root . '/tools/hosting/db-client-config.php'];
        if ($mode !== '') {
            $args[] = $mode;
        }
        $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    public function testRestoreModeUsesOwnUserWhenSet(): void
    {
        $out = $this->runConfig('restore', ['BACKUP_RESTORE_USER' => 'u1_cnnctrestor', 'BACKUP_RESTORE_PASS' => 'a"b;c#d']);
        self::assertStringContainsString('user="u1_cnnctrestor"', $out);
        self::assertStringContainsString('password="a\\"b;c#d"', $out);
        self::assertStringNotContainsString('senha-sistema', $out);
    }

    public function testRestoreModeFallsBackToSystemUser(): void
    {
        $out = $this->runConfig('restore');
        self::assertStringContainsString('user="sistema"', $out);
    }

    public function testDefaultModeIgnoresRestoreUser(): void
    {
        $out = $this->runConfig('', ['BACKUP_RESTORE_USER' => 'u1_cnnctrestor', 'BACKUP_RESTORE_PASS' => 'x']);
        self::assertStringContainsString('user="sistema"', $out);
        self::assertStringNotContainsString('u1_cnnctrestor', $out);
    }
}
