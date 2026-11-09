<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\DocumentVerification;
use Source\Services\Erp\DocumentVerificationThrottle;
use Source\Services\Erp\UnitDocuments;

/**
 * Verificação pública de documento (ADR 0098): formato do código, mensagem genérica, nada de dado pessoal
 * e limite que falha fechado. Usa um SQLite em memória com as colunas que a consulta lê.
 */
final class DocumentVerificationTest extends TestCase
{
    private const IP = '203.0.113.7';

    public function testCodeFormat(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $code = UnitDocuments::newCode();
            self::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{16}$/', $code, 'Sem 0, O, 1, I nem L.');
            self::assertSame($code, UnitDocuments::normalizeCode(strtolower(implode('-', str_split($code, 4)))));
        }
        self::assertSame('ABCDEFGHJKMNPQRS', UnitDocuments::normalizeCode(' abcd efgh-jkmn-pqrs '));
        foreach (['', 'ABCD', 'ABCDEFGHJKMNPQR0', 'ABCDEFGHJKMNPQRO', 'ABCDEFGHJKMNPQRI', 'ABCDEFGHJKMNPQRS2', "ABCDEFGHJKMNPQR'"] as $bad) {
            self::assertNull(UnitDocuments::normalizeCode($bad), $bad);
        }
    }

    public function testAuthenticShowsOnlyKindDateAndCondominium(): void
    {
        $pdo = self::database();
        $result = (new DocumentVerification($pdo))->check('abcd-efgh-jkmn-pqrs', self::IP);
        self::assertTrue($result['ok']);
        self::assertSame(DocumentVerification::AUTHENTIC, $result['message']);
        self::assertSame(['kind' => 'Certidão de Débitos', 'issued_on' => '09/11/2026', 'condominium' => 'Residencial Alpha'], $result['document']);
        $flat = json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Eva Moradora', '123456', '1.234,56', '101', 'debts', '529'] as $private) {
            self::assertStringNotContainsString($private, (string)$flat, 'Vazou: ' . $private);
        }
        self::assertNull(DocumentVerification::present(['kind' => 'debt_certificate', 'status' => 'cancelled', 'created_at' => '2026-11-09 10:00:00']));
        self::assertNull(DocumentVerification::present(['kind' => 'outro', 'status' => 'issued', 'created_at' => '2026-11-09 10:00:00']));
    }

    public function testCancelledMissingAndMalformedGetTheSameGenericMessage(): void
    {
        $pdo = self::database();
        $service = new DocumentVerification($pdo);
        $generic = ['ok' => false, 'message' => DocumentVerification::GENERIC, 'document' => null];
        foreach (['TUVW-XYZ2-3456-789A', 'BCDE-FGHJ-KMNP-QRST', 'nao-e-codigo', '', "' OR 1=1 --"] as $code) {
            self::assertSame($generic, $service->check($code, self::IP), $code);
        }
        self::assertSame(5, (int)$pdo->query("SELECT COUNT(*) FROM auth_throttle_events WHERE scope = 'doc_verify'")->fetchColumn(), 'Cada erro conta no limite.');
        $service->check('ABCD-EFGH-JKMN-PQRS', self::IP);
        self::assertSame(5, (int)$pdo->query('SELECT COUNT(*) FROM auth_throttle_events')->fetchColumn(), 'Acerto não conta.');
    }

    public function testThrottleBlocksAndFailsClosed(): void
    {
        $pdo = self::database();
        $service = new DocumentVerification($pdo);
        $now = 1_800_000_000;
        for ($i = 0; $i < DocumentVerificationThrottle::MAX_FAILURES; $i++) {
            $service->check('TUVW-XYZ2-3456-789A', self::IP, $now);
        }
        $blocked = $service->check('ABCD-EFGH-JKMN-PQRS', self::IP, $now);
        self::assertSame([false, DocumentVerification::THROTTLED, null], [$blocked['ok'], $blocked['message'], $blocked['document']], 'Bloqueado nem consulta.');
        self::assertTrue($service->check('ABCD-EFGH-JKMN-PQRS', '198.51.100.4', $now)['ok'], 'Outro IP segue.');
        self::assertTrue($service->check('ABCD-EFGH-JKMN-PQRS', self::IP, $now + DocumentVerificationThrottle::WINDOW + 1)['ok'], 'A janela passa.');

        $unavailable = ['ok' => false, 'message' => DocumentVerification::UNAVAILABLE, 'document' => null];
        self::assertSame($unavailable, (new DocumentVerification(new PDO('sqlite::memory:')))->check('ABCD-EFGH-JKMN-PQRS', self::IP), 'Sem a tabela do limite, recusa.');
        self::assertSame($unavailable, $service->check('ABCD-EFGH-JKMN-PQRS', null), 'Sem IP, recusa.');
        self::assertSame($unavailable, $service->check('ABCD-EFGH-JKMN-PQRS', 'nao-e-ip'));
        self::assertNull(DocumentVerificationThrottle::subject(''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)DocumentVerificationThrottle::subject(self::IP), 'Só o HMAC do IP.');
    }

    private static function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE auth_throttle_events (id INTEGER PRIMARY KEY, scope TEXT, subject_hash TEXT, created_at INTEGER)');
        $pdo->exec('CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE erp_unit_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, kind TEXT, result TEXT,
            total_cents INTEGER, payload_json TEXT, verification_code TEXT, status TEXT, created_at TEXT)');
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Residencial Alpha')");
        $payload = json_encode(['resident' => 'Eva Moradora', 'document' => '529.982.247-25', 'unit' => '101', 'total' => '1.234,56']);
        $insert = $pdo->prepare('INSERT INTO erp_unit_documents (condominium_id, unit_id, kind, result, total_cents, payload_json, verification_code, status, created_at)
            VALUES (1, 101, ?, ?, 123456, ?, ?, ?, ?)');
        $insert->execute(['debt_certificate', 'debts', $payload, 'ABCDEFGHJKMNPQRS', 'issued', '2026-11-09 10:15:00']);
        $insert->execute(['quittance', 'clear', $payload, 'BCDEFGHJKMNPQRST', 'cancelled', '2026-11-08 09:00:00']);
        return $pdo;
    }
}
