<?php

declare(strict_types=1);

namespace MovesOSTests;

use PDO;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Source\Core\Connect;
use Source\Services\SessionRevocation;
use Source\Support\Access;

abstract class TestCase extends PHPUnitTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (Connect::databaseDisabled()) {
            if (str_starts_with(static::class, 'MovesOSTests\\Integration\\')) {
                self::markTestSkipped('Integração exige MariaDB (MOVESOS_TEST_DB_DISABLED=1).');
            }
        } else {
            self::assertIsolatedTestDatabase();
            $this->pdo = Connect::getInstance();
            $this->resetDatabase();
        }
        Access::clear();
        SessionRevocation::clearCache();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Access::clear();
        parent::tearDown();
    }

    protected function createUser(array $attributes = []): int
    {
        $defaults = [
            'first_name' => 'Usuário', 'last_name' => 'Teste',
            'email' => 'user' . bin2hex(random_bytes(4)) . '@test.local',
            // CPF válido: User::save() revalida o documento com is_document(), então um número
            // aleatório faria qualquer teste que salve o usuário falhar por causa do fixture.
            'document' => self::validCpf(),
            'password' => passwd('Senha@123'), 'level' => 1, 'status' => 'confirmed'
        ];
        $data = array_merge($defaults, $attributes);
        $statement = $this->pdo->prepare('INSERT INTO users (first_name,last_name,email,document,password,level,status) VALUES (:first_name,:last_name,:email,:document,:password,:level,:status)');
        $statement->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    /** CPF aleatório com dígitos verificadores corretos (somente dígitos). */
    public static function validCpf(): string
    {
        do {
            $digits = array_map(static fn(): int => random_int(0, 9), range(1, 9));
        } while (count(array_unique($digits)) === 1);
        foreach ([10, 11] as $weight) {
            $sum = 0;
            foreach ($digits as $index => $digit) {
                $sum += $digit * ($weight - $index);
            }
            $digits[] = ($sum * 10) % 11 % 10;
        }
        return implode('', $digits);
    }

    /**
     * Trava de segurança: resetDatabase() faz TRUNCATE em users, posts, pages etc.
     * Chamar vendor/bin/phpunit direto carrega o Config.php antes de MOVESOS_ENV=testing
     * valer e conecta no banco do app (incidente de 2026-11-07, connect_legacy zerado).
     * Por isso a suíte para aqui se o boot não estiver em modo testing ou se o banco
     * conectado não terminar em _test.
     */
    private static function assertIsolatedTestDatabase(): void
    {
        $environment = defined('CONF_APP_ENV') ? CONF_APP_ENV : '';
        $database = defined('CONF_DB_NAME') ? (string)CONF_DB_NAME : '';
        if ($environment !== 'testing' || !preg_match('/_test$/', $database)) {
            fwrite(STDERR, "\nSuíte abortada: banco '{$database}' (ambiente '{$environment}') não é um banco de teste. Use composer test:legacy, test:unit ou test:integration.\n");
            exit(2);
        }
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['auth_environment_handoffs','inspection_report_deliveries','inspection_photos','inspection_answers','inspections','inspection_plans','inspection_template_items','inspection_templates','condominium_geolocations','condominium_onboarding_items','erp_nfe_documents','erp_settings','erp_cost_center_openings','erp_budget_lines','erp_banners','erp_taxes','erp_legal_case_events','erp_legal_case_entries','erp_legal_cases','erp_collection_contacts','erp_assembly_votes','erp_assembly_attendance','erp_assembly_items','erp_assemblies','erp_announcements','erp_bank_statement_lines','erp_imports','erp_patrimonial_entries','erp_chart_account_openings','erp_bank_transfers','erp_extra_charges','erp_patrimonial_imports','erp_entry_adjustments','erp_unit_document_entries','erp_notification_deliveries','erp_notification_outbox','erp_month_closings','erp_bank_webhook_deliveries','erp_bank_credentials','erp_bank_events','erp_bank_return_files','erp_bank_charges','erp_agreement_originals','erp_agreements','erp_billing_rules','erp_price_indexes','erp_deliveries','erp_access_logs','erp_visit_forecasts','erp_lost_found','erp_gatehouses','erp_unit_documents','erp_meter_readings','erp_meters','erp_tariffs','erp_delivery_notices','erp_check_copies','erp_commitments','erp_pouches','erp_maintenances','erp_occurrences','erp_reservations','erp_waitlist','erp_insurances','erp_contracts','erp_moves','erp_move_settings','erp_documents','erp_entries','erp_invoices','erp_invoice_items','erp_recurring_entries','erp_units','erp_resident_portal_permissions','erp_resident_invitations','erp_partner_agency_users','erp_partner_agencies','erp_residents','erp_board_members','erp_employees','erp_suppliers','erp_lawyers','erp_vehicles','erp_visitors','erp_pets','erp_useful_contacts','erp_common_areas','erp_linkable_areas','erp_assets','erp_bank_accounts','erp_cost_centers','erp_invoice_types','erp_chart_accounts','user_password_resets','user_recovery_codes','user_two_factor','user_condominium_access','condominiums','studio_support_ticket_attachments','studio_support_ticket_events','studio_support_templates','studio_support_ticket_messages','studio_support_tickets','studio_calendar_events','movesos_versions','notifications','notification_messages','notifications_categories','mail_queue','support_articles','support_categories','faq_questions','faq_channels','posts','pages','categories','access_user_overrides','access_user_roles','access_role_permissions','access_permissions','access_roles','system_audit_logs','app_log','users'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        // Tabelas de autenticação sem FK (ADR 0023 e 0036): falhas de login e logouts de um teste
        // não podem bloquear ou derrubar o login do próximo.
        foreach (['auth_throttle_events', 'user_session_revocations'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
        $this->pdo->exec('UPDATE settings SET access_studio=1,access_erp=1,access_app=1,access_site=1,access_support=1 WHERE id=1');
    }

    /**
     * Cadastra em access_permissions todos os slugs das migrations, sem papel, como no banco
     * real. Sem isso o TRUNCATE deixa studio.access sem cadastro, Access::can nega (issue #8) e
     * os controllers do Studio caem em redirect() para o login.
     */
    protected function registerMigrationPermissions(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/database/legacy-migrations/migrations/*.sql') ?: [],
            glob($root . '/database/migrations/*.sql') ?: [],
            glob($root . '/container/cms/studio/v1/components/*/database/*.sql') ?: []
        );
        $insert = $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'Teste')");
        foreach ($files as $file) {
            if (str_ends_with($file, '.down.sql')) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            if (!preg_match_all('/INSERT[^;]*?INTO\s+`?access_permissions`?[^;]*?VALUES(.*?);/is', $sql, $blocks)) {
                continue;
            }
            foreach ($blocks[1] as $values) {
                preg_match_all("/\\(\\s*'([^']*)'\\s*,\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", $values, $tuples, PREG_SET_ORDER);
                foreach ($tuples as [, $name, $slug]) {
                    $insert->execute([$name, $slug]);
                }
            }
        }
        Access::clear();
    }
}
