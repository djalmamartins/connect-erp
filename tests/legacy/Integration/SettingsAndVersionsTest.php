<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Settings\Settings;

final class SettingsAndVersionsTest extends TestCase
{
    public function testUpdatesSettingsWithoutCreatingAnotherConfigurationRow(): void
    {
        $settings = Settings::dados();
        $settings->site_title = 'Título alterado no teste';

        self::assertTrue($settings->save());
        self::assertSame('Título alterado no teste', Settings::dados()?->site_title);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
    }

    public function testRequiredThemeEntrypointsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/container/web/default/v1/default.php');
        self::assertFileExists($root . '/container/web/default/v1/routes.php');
        self::assertFileExists($root . '/container/cms/studio/v1/default.php');
        self::assertFileExists($root . '/container/cms/studio/v1/routes.php');
        self::assertFileExists($root . '/container/cms/studio/v1/layouts/error.php');
        self::assertDirectoryExists($root . '/container/cms/studio/v1/components');
        self::assertDirectoryExists($root . '/container/web/default/v1/pages');
        self::assertFileExists($root . '/container/erp/connect/v1/default.php');
        self::assertFileExists($root . '/container/erp/connect/v1/routes.php');
    }

    public function testCanonicalArchitectureHasNoLegacyParallelTrees(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['container/themes', 'container/studio', 'source/Public', 'source/Minify'] as $legacy) {
            self::assertDirectoryDoesNotExist($root . '/' . $legacy);
        }

        self::assertSame($root . '/container/web/default/v1', moves_container_path('web', 'connect_by_moves'));
        self::assertSame($root . '/container/cms/studio/v1', moves_container_path('studio', 'moves_studio'));
    }

    public function testVersionsAreIndependentByProduct(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO movesos_versions (product,version,name,status,published_at) VALUES (?,?,?,'current',NOW())");
        $insert->execute(['web', '1.2.0', 'Web']);
        $insert->execute(['studio', '1.2.0', 'Studio']);
        $insert->execute(['support', '1.2.0', 'Suporte']);

        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM movesos_versions WHERE version='1.2.0'")->fetchColumn());
        $this->pdo->exec("UPDATE movesos_versions SET status='archived' WHERE product='web'");
        self::assertSame('current', $this->pdo->query("SELECT status FROM movesos_versions WHERE product='studio'")->fetchColumn());
        self::assertSame('current', $this->pdo->query("SELECT status FROM movesos_versions WHERE product='support'")->fetchColumn());
    }
}
