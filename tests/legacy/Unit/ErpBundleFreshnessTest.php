<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;
use PHPUnit\Framework\TestCase;

/**
 * Conferência geral de 10/11/2026: o erp.min.css versionado estava sem o CSS da ficha da pessoa
 * (ADR 0155). O default.php usa o bundle quando ele é mais novo que o fonte — e depois de um
 * git pull as datas dos arquivos são as do checkout, então um bundle velho pode ser servido.
 * Este teste gera os bundles de novo, a partir dos fontes, e exige que sejam iguais aos versionados.
 */
final class ErpBundleFreshnessTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../../../container/erp/connect/v1/assets';

    /** @return array<string, array{class-string, string, string}> */
    public static function bundles(): array
    {
        return [
            'erp.min.css' => [CSS::class, '/css/*.css', '/erp.min.css'],
            'erp.min.js' => [JS::class, '/js/*.js', '/erp.min.js'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bundles')]
    public function testCommittedBundleMatchesSources(string $compressor, string $pattern, string $bundle): void
    {
        $sources = glob(self::ASSETS . $pattern) ?: [];
        self::assertNotSame([], $sources);
        sort($sources);
        $target = tempnam(sys_get_temp_dir(), 'erp-bundle-');
        (new $compressor(...$sources))->minify($target);
        $fresh = (string)file_get_contents($target);
        @unlink($target);
        self::assertSame($fresh, (string)file_get_contents(self::ASSETS . $bundle), $bundle . ' desatualizado: abra uma página do ERP no lab (ou rode a minificação) e versione o bundle gerado.');
    }
}
