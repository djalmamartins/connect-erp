<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Core\View;
use Source\Services\Erp\Registry\Definitions;

/**
 * Campo de anexo do Disco Virtual nos cadastros AP-126 a 129 (ADR 0050 e 0052): guarda o nº do
 * arquivo num campo oculto e oferece escolher ou enviar pela API /api/v1/documents.
 */
final class ErpRegistryAttachmentFieldTest extends TestCase
{
    private function render(array $attachment, mixed $value, bool $readonly = false): string
    {
        $field = Definitions::get('check_copies')['fields']['document_id'];
        return (new View(moves_container_path('erp', 'connect') . '/'))->render('components/forms/registry-field', [
            'field' => $field, 'value' => $value, 'error' => null, 'readonly' => $readonly, 'condominiums' => [],
            'attachment' => $attachment + ['field' => 'document_id', 'file' => null, 'canBrowse' => true, 'canUpload' => true],
            'icon' => static fn (string $name, string $class = ''): string => '<svg data-icon="' . $name . '"></svg>',
        ]);
    }

    public function testEveryDocumentRegistryHasAnAttachmentField(): void
    {
        foreach (['delivery_notices', 'check_copies', 'commitments', 'pouches'] as $slug) {
            $definition = Definitions::get($slug);
            self::assertArrayHasKey($definition['attachment'], $definition['fields'], $slug);
        }
    }

    public function testChosenFileShowsTitleAndDownloadAndOffersPickAndUpload(): void
    {
        $html = $this->render(['file' => ['id' => 12, 'title' => 'Cheque <123>', 'original_name' => 'cheque.jpg']], 12);
        self::assertMatchesRegularExpression('/<input type="hidden" id="f-document_id" name="document_id" value="12" data-attachment-value/', $html);
        self::assertStringContainsString('Cheque &lt;123&gt;', $html);
        self::assertStringContainsString('/api/v1/documents/12/download', $html);
        self::assertStringContainsString('data-attachment-open>', $html);
        self::assertStringContainsString('data-attachment-open="upload"', $html);
        self::assertStringContainsString('data-attachment-send', $html);
        self::assertStringNotContainsString('type="number"', $html, 'o nº do arquivo não é mais digitado');
    }

    public function testWithoutUploadPermissionOnlyPicks(): void
    {
        $html = $this->render(['canUpload' => false], null);
        self::assertStringContainsString('Nenhum arquivo anexado', $html);
        self::assertStringContainsString('data-attachment-open>', $html);
        self::assertStringNotContainsString('data-attachment-send', $html);
    }

    public function testReadonlyAndNoDiskAccessShowOnlyTheFile(): void
    {
        $html = $this->render(['file' => ['id' => 3, 'title' => 'Malote', 'original_name' => 'm.pdf']], 3, true);
        self::assertStringContainsString('/api/v1/documents/3/download', $html);
        self::assertStringNotContainsString('data-attachment-open', $html);
        self::assertStringNotContainsString('<dialog', $html);
        $html = $this->render(['canBrowse' => false, 'canUpload' => false], 9);
        self::assertStringContainsString('Arquivo nº 9', $html);
        self::assertStringNotContainsString('<dialog', $html);
    }

    public function testJavascriptInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/' . $file);
            foreach (['[data-erp-attachment]', 'sendMultipart', '?condominium_id=', 'data-attachment-use', 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
        self::assertStringContainsString("'registryAttachment' => self::attachmentData(\$user, \$definition, \$values)", (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Erp/Connect/Registry.php'));
    }
}
