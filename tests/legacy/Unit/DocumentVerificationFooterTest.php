<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\DocumentVerification;
use Source\Services\Erp\UnitDocuments;

/**
 * ADR 0099: código e link da verificação pública impressos nos documentos da unidade, e o código aceito com
 * ou sem hífens e espaços, em maiúsculas ou minúsculas.
 */
final class DocumentVerificationFooterTest extends TestCase
{
    private const VIEW = 'container/erp/connect/v1/pages/documents/unit-document.php';

    public function testNormalizationAcceptsWhatPeopleTypeOrPaste(): void
    {
        foreach (['ABCDEFGHJKMNPQRS', 'abcd-efgh-jkmn-pqrs', ' ABCD EFGH JKMN PQRS ', "abcd\tefgh\njkmn pqrs", 'ABCD–EFGH—JKMN‑PQRS', "ABCD\u{00A0}EFGH\u{202F}JKMN-PQRS", 'aBcD-eFgH jKmN-pQrS'] as $typed) {
            self::assertSame('ABCDEFGHJKMNPQRS', DocumentVerification::normalize($typed), json_encode($typed));
        }
        foreach ([null, '', 'ABCD-EFGH-JKMN', 'ABCD-EFGH-JKMN-PQR0', 'ABCD-EFGH-JKMN-PQRI', 'ABCD.EFGH.JKMN.PQRS', str_repeat('A', 65), "ABCD-EFGH-JKMN-PQRS'"] as $bad) {
            self::assertNull(DocumentVerification::normalize($bad), json_encode($bad));
        }
    }

    public function testFormattingAndLink(): void
    {
        self::assertSame('ABCD-EFGH-JKMN-PQRS', DocumentVerification::formatCode('abcdefghjkmnpqrs'));
        self::assertNull(DocumentVerification::formatCode('curto'));
        for ($i = 0; $i < 10; $i++) {
            $code = UnitDocuments::newCode();
            self::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}(-[A-HJKMNP-Z2-9]{4}){3}$/', (string)DocumentVerification::formatCode($code));
            self::assertSame($code, DocumentVerification::normalize(DocumentVerification::formatCode($code)), 'O código impresso volta ao gravado.');
        }
        self::assertSame('https://www.exemplo.test/verificar#ABCD-EFGH-JKMN-PQRS', DocumentVerification::link('ABCDEFGHJKMNPQRS', 'https://www.exemplo.test/'));
        self::assertSame('http://localhost/connect/verificar#ABCD-EFGH-JKMN-PQRS', DocumentVerification::link('abcd efgh jkmn pqrs', 'http://localhost/connect'));
        foreach (['', 'javascript:alert(1)', '//sem-esquema.test', 'https://exemplo.test/?q=1', 'https://exemplo.test/#x', 'ftp://exemplo.test'] as $badBase) {
            self::assertNull(DocumentVerification::link('ABCDEFGHJKMNPQRS', $badBase), $badBase);
        }
        self::assertNull(DocumentVerification::link('invalido', 'https://www.exemplo.test'));
        self::assertSame(['code' => 'ABCD-EFGH-JKMN-PQRS', 'address' => 'https://www.exemplo.test/verificar', 'link' => 'https://www.exemplo.test/verificar#ABCD-EFGH-JKMN-PQRS'],
            DocumentVerification::footer('ABCDEFGHJKMNPQRS', 'https://www.exemplo.test'));
        self::assertStringNotContainsString('ABCD', DocumentVerification::footer('ABCDEFGHJKMNPQRS', 'https://www.exemplo.test')['address'], 'O endereço mostrado não leva o código.');
    }

    public function testEveryUnitDocumentPrintsTheFooter(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 3) . '/' . self::VIEW);
        // Todos os tipos (certidão, declaração, quitação, anuência e recibo) usam este mesmo modelo de impressão.
        self::assertSame(['debt_certificate', 'delinquency_declaration', 'quittance', 'consent_letter', 'receipt'], array_keys(UnitDocuments::ALL_KINDS));
        self::assertSame(1, substr_count($view, '<footer class="erp-unit-doc__foot"'), 'Um rodapé, comum a todos os tipos.');
        foreach (['DocumentVerification::footer(', 'DocumentVerification::configuredBase())', 'Código de verificação: <strong>', 'Confira em <a href="<?= $this->e($verification[\'link\']) ?>"',
            '$this->e($verification[\'address\'])', '\Source\Support\QrCode::svg($verification[\'link\']'] as $needle) {
            self::assertStringContainsString($needle, $view, $needle);
        }
        self::assertStringNotContainsString('api.qrserver', $view, 'Sem serviço externo de QR.');
        self::assertStringNotContainsString('getenv(', $view);

        $page = (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/default/v1/pages/verify.php');
        self::assertStringContainsString('window.location.hash', $page, 'A página lê o código do fragmento.');
        self::assertStringContainsString('history.replaceState', $page, 'E tira o código do endereço.');
        self::assertStringNotContainsString('.submit()', $page, 'Não envia sozinha.');
        self::assertStringNotContainsString('requestSubmit', $page);
    }
}
