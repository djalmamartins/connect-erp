<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\PlainText;

/** ADR 0097: texto simples sem falso positivo e auditoria das configurações com CPF/CNPJ mascarado. */
final class PlainTextAndSettingsAuditTest extends TestCase
{
    public function testLegitimateTextIsAccepted(): void
    {
        foreach (['Sr.João vai trocar o portão.', 'Taxa extra de R$1.500 em dezembro.', 'Obra no Bloco A.2 até sexta.', 'obs.: trazer RG na portaria.',
            'Saldo de 10.000,00 no fundo de reserva.', 'Reunião às 19h.Confirme presença.', 'Dra.Ângela atende no térreo.', 'Versão 2.0 do regulamento.',
            'Ex.: chave na portaria.', 'Pagamento até 10.12.2026.', 'Assembleia: item 3.1 e 3.2.', 'Fim de ano.Feliz Natal!'] as $ok) {
            self::assertNull(PlainText::problem($ok), $ok);
        }
    }

    public function testRealAddressesAreRefused(): void
    {
        foreach (['Pague em golpe.com', 'Acesse pix.link/abc', 'Veja bit.ly/x', 'Site exemplo.com.br', 'WWW.X.COM', 'https://banco.test', 'Clique //golpe.top',
            'Atualize em app-boleto.online/2via', 'segunda via em boleto.net.br', 'contato.me', 'goo.gl/xyz', 'meu.site', '<a href=x>oi</a>', 'javascript:alert(1)',
            'mailto:fulano@x.test', 'tel:+5511999999999', 'pagamento.co/qr'] as $bad) {
            self::assertSame(PlainText::MESSAGE, PlainText::problem($bad), $bad);
        }
    }

    public function testSettingsAuditMasksDocumentsInFreeText(): void
    {
        $text = ['type' => 'text'];
        $masked = ErpSettings::auditValue($text, 'Responsável CPF 529.982.247-25, CNPJ 11.222.333/0001-81');
        self::assertStringNotContainsString('529.982.247-25', (string)$masked);
        self::assertStringNotContainsString('11.222.333/0001-81', (string)$masked);
        self::assertStringContainsString('Responsável', (string)$masked, 'O texto em volta continua.');
        self::assertStringNotContainsString('52998224725', (string)ErpSettings::auditValue($text, 'doc 52998224725'));
        self::assertSame('Vencimento dia 10', ErpSettings::auditValue($text, 'Vencimento dia 10'), 'Texto sem dado pessoal não muda.');
        self::assertSame(10, ErpSettings::auditValue(['type' => 'int'], 10));
    }
}
