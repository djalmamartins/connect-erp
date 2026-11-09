<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;

/** Avisos de recebimento, cópias de cheques, empenhos e malotes: definições e anexo (ADR 0050). */
final class DocumentRegistriesTest extends TestCase
{
    public function testDefinitionsUseAttachmentFromVirtualDisk(): void
    {
        $expected = ['delivery_notices' => 'AP-126', 'check_copies' => 'AP-127', 'commitments' => 'AP-128', 'pouches' => 'AP-129'];
        foreach ($expected as $slug => $page) {
            $definition = Definitions::get($slug);
            self::assertSame([$page, 'documentos', 'required', 'document_id'], [$definition['page'], $definition['group'], $definition['scope'], $definition['attachment']], $slug);
            self::assertSame('int', $definition['fields']['document_id']['type'], $slug);
            self::assertArrayHasKey('active', $definition['status'], "{$slug}: novo registro nasce com a situação padrão");
        }
        self::assertTrue(Definitions::get('check_copies')['fields']['document_id']['required'], 'Cópia de cheque exige a imagem.');
        self::assertFalse(Definitions::get('pouches')['fields']['document_id']['required']);
    }

    public function testRulesForDatesAndUniqueness(): void
    {
        self::assertSame('sent_on', Definitions::get('delivery_notices')['fields']['delivered_on']['after']);
        self::assertSame('committed_on', Definitions::get('commitments')['fields']['settled_on']['after']);
        self::assertSame('received_on', Definitions::get('pouches')['fields']['checked_on']['after']);
        self::assertSame(['condominium_id', 'bank_account_id', 'check_number'], Definitions::get('check_copies')['unique'][0]['fields']);
        self::assertSame('entries', Definitions::get('commitments')['fields']['entry_id']['ref'], 'Lançamento apontado não pode ser excluído (ADR 0020).');
    }
}
