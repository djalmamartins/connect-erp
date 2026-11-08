<?php

declare(strict_types=1);

namespace MovesOSTests;

use PDO;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Source\Core\Connect;
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
        foreach (['condominium_onboarding_items','erp_residents','erp_board_members','erp_employees','erp_suppliers','erp_lawyers','erp_vehicles','erp_visitors','erp_pets','erp_useful_contacts','user_password_resets','user_recovery_codes','user_two_factor','user_condominium_access','condominiums','studio_support_ticket_attachments','studio_support_ticket_events','studio_support_templates','studio_support_ticket_messages','studio_support_tickets','studio_calendar_events','movesos_versions','notifications','notification_messages','notifications_categories','mail_queue','support_articles','support_categories','faq_questions','faq_channels','posts','pages','categories','access_user_overrides','access_user_roles','access_role_permissions','access_permissions','access_roles','system_audit_logs','app_log','users'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec('UPDATE settings SET access_studio=1,access_erp=1,access_app=1,access_site=1,access_support=1 WHERE id=1');
    }
}
