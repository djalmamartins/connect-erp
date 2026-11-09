<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\VirtualDisk;

/** Disco Virtual: nomes, tipos aceitos e caminho preso à pasta privada (ADR 0047). */
final class VirtualDiskTest extends TestCase
{
    public function testCleanNameAndExtension(): void
    {
        self::assertSame('ata 2026.pdf', VirtualDisk::cleanName('../../etc/ata  2026.pdf'));
        self::assertSame('contrato.pdf', VirtualDisk::cleanName("C:\\Users\\x\\contra\"to.pdf"));
        self::assertSame('arquivo', VirtualDisk::cleanName('...'));
        self::assertSame('pdf', VirtualDisk::extension('Ata.PDF'));
        self::assertSame('', VirtualDisk::extension('sem-extensao'));
        self::assertSame('', VirtualDisk::extension('x.php5.toolong'), 'Extensão estranha não passa.');
    }

    public function testOnlyKnownTypeWithMatchingExtension(): void
    {
        self::assertTrue(VirtualDisk::accepts('application/pdf', 'pdf'));
        self::assertTrue(VirtualDisk::accepts('image/jpeg', 'jpeg'));
        self::assertFalse(VirtualDisk::accepts('application/pdf', 'php'), 'Extensão que não bate com o tipo.');
        self::assertFalse(VirtualDisk::accepts('text/x-php', 'php'));
        self::assertFalse(VirtualDisk::accepts('text/html', 'html'));
        self::assertFalse(VirtualDisk::accepts('image/svg+xml', 'svg'), 'SVG pode carregar script.');
        self::assertFalse(VirtualDisk::accepts('application/zip', 'docx'), 'ZIP disfarçado de DOCX.');
    }

    public function testStoredPathCannotEscapeTheFolder(): void
    {
        $root = sys_get_temp_dir() . '/vd-' . bin2hex(random_bytes(4));
        mkdir($root . '/1/2026', 0700, true);
        $name = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($root . '/1/2026/' . $name, '%PDF-1.4');
        $disk = new VirtualDisk(new \PDO('sqlite::memory:'), null, $root);
        self::assertSame($root . '/1/2026/' . $name, $disk->absolute('1/2026/' . $name));
        foreach (['../1/2026/' . $name, '1/2026/../../x.pdf', '/etc/passwd', '1/26/' . $name, '1/2026/abc.pdf'] as $bad) {
            self::assertNull($disk->absolute($bad), $bad);
        }
        unlink($root . '/1/2026/' . $name);
        rmdir($root . '/1/2026');
        rmdir($root . '/1');
        rmdir($root);
    }
}
