<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\DeployReadiness;
use Source\Support\Storage\DocumentStores;
use Source\Support\Storage\GraphTransport;
use Source\Support\Storage\LocalDocumentStore;
use Source\Support\Storage\OneDriveDocumentStore;

/** ADR 0374: o .env escolhe disco local ou OneDrive; incompleto fica no local e o deploy-check acusa. */
final class DocumentStoresTest extends TestCase
{
    private const ONEDRIVE = ['DOCUMENT_STORAGE' => 'OneDrive', 'ONEDRIVE_TENANT_ID' => 't', 'ONEDRIVE_CLIENT_ID' => 'c',
        'ONEDRIVE_CLIENT_SECRET' => 's', 'ONEDRIVE_DRIVE_ID' => 'd', 'ONEDRIVE_FOLDER' => '/ConnectERP/'];

    public function testLocalByDefaultAndWhenOneDriveIsIncomplete(): void
    {
        self::assertInstanceOf(LocalDocumentStore::class, DocumentStores::make('documents', '/tmp/x', []));
        self::assertInstanceOf(LocalDocumentStore::class, DocumentStores::make('documents', '/tmp/x', ['DOCUMENT_STORAGE' => 'local'] + self::ONEDRIVE));
        self::assertInstanceOf(LocalDocumentStore::class, DocumentStores::make('documents', '/tmp/x', ['ONEDRIVE_CLIENT_SECRET' => ' '] + self::ONEDRIVE));
        self::assertSame('/tmp/x', DocumentStores::make('inspections', '/tmp/x/', [])->root());
    }

    public function testOneDrivePerAreaFolder(): void
    {
        $http = $this->createStub(GraphTransport::class);
        $store = DocumentStores::make('resident-photos', '/tmp/x', self::ONEDRIVE, $http);
        self::assertInstanceOf(OneDriveDocumentStore::class, $store);
        self::assertSame('https://graph.microsoft.com/v1.0/drives/d/root:/ConnectERP/resident-photos/1/a.jpg:', $store->itemUrl('1/a.jpg'));
        $this->expectException(\InvalidArgumentException::class);
        DocumentStores::make('../etc', '/tmp/x', self::ONEDRIVE, $http);
    }

    public function testModeAndMissingKeys(): void
    {
        self::assertSame('local', DocumentStores::mode([]));
        self::assertSame('onedrive', DocumentStores::mode(self::ONEDRIVE));
        self::assertSame('', DocumentStores::mode(['DOCUMENT_STORAGE' => 's3']));
        self::assertSame([], DocumentStores::missing(self::ONEDRIVE));
        self::assertSame(['ONEDRIVE_DRIVE_ID', 'ONEDRIVE_FOLDER'], DocumentStores::missing(['ONEDRIVE_DRIVE_ID' => '', 'ONEDRIVE_FOLDER' => ''] + self::ONEDRIVE));
    }

    public function testDeployCheckAccusesIncompleteOrUnknownStorage(): void
    {
        $errors = static fn (array $env): string => implode("\n", DeployReadiness::env($env, time())['errors']);
        self::assertStringNotContainsString('DOCUMENT_STORAGE', $errors(['DOCUMENT_STORAGE' => 'local']));
        self::assertStringNotContainsString('DOCUMENT_STORAGE', $errors(self::ONEDRIVE));
        self::assertStringContainsString('DOCUMENT_STORAGE=onedrive sem ONEDRIVE_CLIENT_SECRET', $errors(['ONEDRIVE_CLIENT_SECRET' => ''] + self::ONEDRIVE));
        self::assertStringContainsString('DOCUMENT_STORAGE precisa ser local ou onedrive', $errors(['DOCUMENT_STORAGE' => 's3']));
    }

    public function testServicesUseTheFactoryOnlyWithoutAnExplicitFolder(): void
    {
        $root = dirname(__DIR__, 3) . '/source/Services/';
        foreach (['Erp/VirtualDisk.php' => "'documents'", 'Inspections/PhotoStorage.php' => "'inspections'", 'Resident/ResidentProfile.php' => "'resident-photos'"] as $file => $area) {
            self::assertStringContainsString('DocumentStores::make(' . $area, (string)file_get_contents($root . $file), $file);
        }
    }
}
