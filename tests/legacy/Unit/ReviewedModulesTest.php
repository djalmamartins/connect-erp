<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\PlainText;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Services\Erp\RegistryReports;

/** Revisão dos módulos de configurações, relatórios, atalhos e acordos (ADR 0096): regras puras. */
final class ReviewedModulesTest extends TestCase
{
    public function testBannersAcceptOnlyPlainText(): void
    {
        self::assertNull(PlainText::problem('Manutenção do elevador na terça, das 9h às 12h.'));
        self::assertNull(PlainText::problem('Assembleia dia 10/12 às 19h30. Taxa: R$ 1.200,50.'));
        foreach (['<b>Aviso</b>', 'Veja em https://exemplo.test', 'Acesse www.exemplo.com', 'clique: javascript:alert(1)', 'pague em pague-ja.com/boleto',
            'entidade &lt;script&gt;', 'mailto:fulano@exemplo.test'] as $bad) {
            self::assertSame(PlainText::MESSAGE, PlainText::problem($bad), $bad);
        }
        $definition = Definitions::get('banners');
        self::assertTrue($definition['fields']['title']['plain'] && $definition['fields']['message']['plain'], 'Título e texto do banner são texto simples.');
        [, $errors] = RegistryValidator::validate($definition, ['title' => 'Aviso', 'message' => 'Boleto novo em https://golpe.test', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        self::assertSame(PlainText::MESSAGE, $errors['message'] ?? null, 'O link no banner é recusado na gravação.');
        [, $ok] = RegistryValidator::validate($definition, ['title' => 'Aviso', 'message' => 'Água desligada sábado de manhã.', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        self::assertArrayNotHasKey('message', $ok);
    }

    public function testAuditMasksSettingValuesAndLogsMaskTheIp(): void
    {
        $email = ErpSettings::CATALOG['admin.support_email'];
        $masked = ErpSettings::auditValue($email, 'atendimento@connect.test');
        self::assertStringNotContainsString('atendimento', (string)$masked, 'O e-mail da configuração sai mascarado na auditoria.');
        self::assertStringEndsWith('@connect.test', (string)$masked);
        self::assertSame(15, ErpSettings::auditValue(['type' => 'int'], 15), 'Valor que não é dado pessoal fica como está.');
        self::assertSame('203.0.113.*', RegistryReports::maskIp('203.0.113.77'));
        self::assertSame('2001:db8:85a3:*', RegistryReports::maskIp('2001:db8:85a3::8a2e:370:7334'));
        self::assertSame('*', RegistryReports::maskIp('nao-e-ip'));
        self::assertNull(RegistryReports::maskIp(null));
    }
}
