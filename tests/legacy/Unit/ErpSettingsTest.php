<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\RegistryReports;

/** Configurações do ERP (ADR 0085): catálogo sem credenciais, padrões de hoje e validação dos valores. */
final class ErpSettingsTest extends TestCase
{
    public function testCatalogHasNoCredentialAndKeepsTodaysDefaults(): void
    {
        foreach (ErpSettings::CATALOG as $key => $spec) {
            self::assertTrue(ErpSettings::safeKey($key), "{$key} não pode guardar credencial.");
            self::assertContains($spec['scope'], ['global', 'condominium'], $key);
            self::assertSame($spec['default'], ErpSettings::normalize($key, $spec['default']), "Padrão de {$key} passa na própria validação.");
        }
        foreach (['bank.account_password', 'smtp.host', 'api.token', 'mail.smtp_user', 'banco.bank_account', 'x.api_key'] as $secret) {
            self::assertFalse(ErpSettings::safeKey($secret), "{$secret} parece credencial.");
        }
        self::assertSame(Announcements::COLLECTION_COOLDOWN_DAYS, ErpSettings::CATALOG['app.collection_cooldown_days']['default'], 'Mesmo padrão de hoje.');
        self::assertSame((string)Imports::FRACTION_TOLERANCE, ErpSettings::CATALOG['params.fraction_tolerance']['default']);
        self::assertSame(RegistryReports::MAX_ROWS, ErpSettings::CATALOG['params.report_max_rows']['default']);
        self::assertSame(10, ErpSettings::CATALOG['finance.default_due_day']['default']);
    }

    public function testNormalizeByType(): void
    {
        self::assertSame(15, ErpSettings::normalize('finance.default_due_day', '15'));
        self::assertFalse(ErpSettings::normalize('closing.notify_board', 'false'));
        self::assertSame(150000, ErpSettings::normalize('alerts.low_balance_cents', '1.500,00'));
        self::assertSame(0, ErpSettings::normalize('alerts.low_balance_cents', '0'));
        self::assertSame('0.0005', ErpSettings::normalize('params.fraction_tolerance', '0,0005'));
        self::assertSame('cost_center', ErpSettings::normalize('statement.group_by', 'cost_center'));
        self::assertSame('atendimento@connect.test', ErpSettings::normalize('admin.support_email', ' Atendimento@Connect.test '));
        foreach ([['finance.default_due_day', '31'], ['closing.notify_board', 'talvez'], ['statement.group_by', 'outro'], ['admin.support_email', 'x@'],
            ['params.fraction_tolerance', '0.5'], ['app.display_name', 'X'], ['nao.existe', '1']] as [$key, $value]) {
            try {
                ErpSettings::normalize($key, $value);
                self::fail("Aceitou {$key} = {$value}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSignedCents(): void
    {
        self::assertSame([-123456, 123456, 0, 500], [ErpSettings::signedCents('-1.234,56'), ErpSettings::signedCents('1234,56'), ErpSettings::signedCents('0'), ErpSettings::signedCents(500)]);
        $this->expectException(InvalidArgumentException::class);
        ErpSettings::signedCents('abc');
    }
}
