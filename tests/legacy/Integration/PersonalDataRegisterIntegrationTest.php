<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;

/**
 * Registro das operações de tratamento (LGPD art. 37, ADR 0350): toda tabela do banco com coluna de dado pessoal (nome de
 * pessoa, documento, e-mail, telefone, placa, IP, foto) precisa aparecer em docs/security/LGPD_REGISTRO_OPERACOES.md. Uma
 * migration nova com dado pessoal faz este teste falhar até o registro ser atualizado.
 */
final class PersonalDataRegisterIntegrationTest extends TestCase
{
    private const PERSONAL = '^(cpf|document|person_document|visitor_document|email|requester_email|recipient_email|phone|requester_phone|to_phone'
        . '|first_name|last_name|ip|created_ip|requested_ip|plate|pix_key|user_agent|resident_name|visitor_name|owner_name|tutor_name'
        . '|attendee_name|contact_name|person_name|requester_name|recipient_name|photo|photo_path|street)$';

    public function testEveryTableWithPersonalDataIsInTheRegister(): void
    {
        $register = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/security/LGPD_REGISTRO_OPERACOES.md');
        $stmt = $this->pdo->prepare("SELECT DISTINCT table_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND column_name REGEXP ?
              AND data_type IN ('varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext')");
        $stmt->execute([self::PERSONAL]);
        $tables = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $missing = array_values(array_filter($tables, static fn (string $table): bool => !str_contains($register, '`' . $table . '`')));

        self::assertGreaterThan(25, count($tables), 'A busca por colunas pessoais deixou de achar as tabelas conhecidas.');
        self::assertSame([], $missing, 'Tabela com dado pessoal fora do registro LGPD (docs/security/LGPD_REGISTRO_OPERACOES.md).');
    }
}
