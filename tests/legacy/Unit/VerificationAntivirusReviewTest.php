<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Controllers\Web\Connect\DocumentVerify;
use Source\Services\Erp\Antivirus\ClamdScanner;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Services\Erp\DocumentVerification;
use Source\Services\Erp\DocumentVerificationThrottle;
use Source\Services\Erp\VirtualDisk;

/** Revisão de segurança dos ADRs 0098 e 0099 (ADR 0160): um teste por correção. */
final class VerificationAntivirusReviewTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testGlobalCeilingBlocksEveryIp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE auth_throttle_events (id INTEGER PRIMARY KEY, scope TEXT, subject_hash TEXT, created_at INTEGER)');
        $throttle = new DocumentVerificationThrottle($pdo);
        $now = 1_800_000_000;
        $fresh = (string)DocumentVerificationThrottle::subject('198.51.100.77');
        self::assertSame(DocumentVerificationThrottle::OK, $throttle->check($fresh, $now));

        self::assertTrue($throttle->fail((string)DocumentVerificationThrottle::subject('203.0.113.1'), $now));
        $rows = $pdo->query('SELECT subject_hash FROM auth_throttle_events')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains(DocumentVerificationThrottle::globalSubject(), $rows, 'Cada falha também soma no total.');
        self::assertNotSame(DocumentVerificationThrottle::globalSubject(), DocumentVerificationThrottle::subject('0.0.0.0'));

        $insert = $pdo->prepare("INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES ('doc_verify', ?, ?)");
        $pdo->beginTransaction();
        for ($i = 1; $i < DocumentVerificationThrottle::GLOBAL_MAX_FAILURES; $i++) {
            $insert->execute([DocumentVerificationThrottle::globalSubject(), $now]);
        }
        $pdo->commit();
        self::assertSame(DocumentVerificationThrottle::BLOCKED, $throttle->check($fresh, $now), 'IP novo, mas o total passou do teto.');
        self::assertSame(DocumentVerificationThrottle::OK, $throttle->check($fresh, $now + DocumentVerificationThrottle::WINDOW + 1), 'A janela anda.');
    }

    public function testMalformedCodeTakesTheSameDatabasePath(): void
    {
        // Sem a tabela dos documentos, a consulta falha. Antes o código malformado nem consultava e respondia
        // "não encontrado" mais rápido; agora passa pela mesma consulta (e aqui cai em indisponível).
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE auth_throttle_events (id INTEGER PRIMARY KEY, scope TEXT, subject_hash TEXT, created_at INTEGER)');
        $service = new DocumentVerification($pdo);
        foreach (['nao-e-codigo', '', 'ABCD-EFGH-JKMN-PQRS'] as $code) {
            self::assertSame(DocumentVerification::UNAVAILABLE, $service->check($code, '203.0.113.5')['message'], $code);
        }
        $source = (string)file_get_contents(self::ROOT . '/source/Services/Erp/DocumentVerification.php');
        self::assertStringContainsString('hash_equals((string)$row[\'verification_code\'], $normalized)', $source, 'Confere o código devolvido em tempo constante.');
    }

    public function testLinkBaseComesOnlyFromConfiguration(): void
    {
        $base = DocumentVerification::configuredBase();
        self::assertTrue($base === null || preg_match('~^https?://[^\s/?#@]+~', $base) === 1);
        $_SERVER['HTTP_HOST'] = 'atacante.lab';
        self::assertSame($base, DocumentVerification::configuredBase(), 'O Host da requisição não muda a base.');
        self::assertNull(DocumentVerification::link('ABCDEFGHJKMNPQRS', 'https://site.test@atacante.test'), 'Sem "@" no host.');
        self::assertNull(DocumentVerification::link('ABCDEFGHJKMNPQRS', null));
        self::assertNull(DocumentVerification::footer('ABCDEFGHJKMNPQRS', null), 'Sem configuração válida, o rodapé sai sem link.');
        $link = (string)DocumentVerification::link('ABCDEFGHJKMNPQRS', 'https://www.exemplo.test');
        self::assertNull(parse_url($link, PHP_URL_QUERY), 'O código não vai na query.');
        self::assertNull(parse_url($link, PHP_URL_FRAGMENT), 'ADR 0115: o link é o endereço fixo, sem o código (a pessoa digita).');
        self::assertStringNotContainsString('ABCD', (string)parse_url($link, PHP_URL_PATH));

        $view = (string)file_get_contents(self::ROOT . '/container/erp/connect/v1/pages/documents/unit-document.php');
        self::assertStringContainsString('DocumentVerification::configuredBase()', $view);
        self::assertStringNotContainsString("\$verification['code']), url()", $view);
        self::assertSame(0, preg_match('/DocumentVerification::footer\([^;]*url\(\)/', $view), 'O rodapé não usa url(), que lê o Host.');
    }

    public function testPublicPageHeadersAndConstantTimeCsrf(): void
    {
        foreach (['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'", 'X-Content-Type-Options' => 'nosniff'] as $name => $value) {
            self::assertStringContainsString($value, DocumentVerify::HEADERS[$name] ?? '', $name);
        }
        $token = bin2hex(random_bytes(32));
        self::assertTrue(DocumentVerify::csrfMatches($token, $token));
        foreach ([[$token, substr($token, 0, -1)], [$token, ''], [$token, null], [$token, [$token]], ['', ''], [null, null], [0, '0'], [$token, strtoupper($token)]] as [$session, $sent]) {
            self::assertFalse(DocumentVerify::csrfMatches($session, $sent), json_encode([$session, $sent]));
        }
        $controller = (string)file_get_contents(self::ROOT . '/source/Controllers/Web/Connect/DocumentVerify.php');
        self::assertStringNotContainsString('csrf_verify($data)', $controller, 'Usa a comparação em tempo constante.');
        self::assertStringContainsString("self::csrfMatches((new Session())->csrf_token ?? null, \$data['csrf'] ?? null)", $controller);
        self::assertStringNotContainsString('$_GET', $controller, 'O código não é lido da query string.');
    }

    public function testClamdRefusesOversizedStreamsAndIncompleteReplies(): void
    {
        $calls = 0;
        $scanner = new ClamdScanner('unix:///falso.sock', static function () use (&$calls) {
            $calls++;
            return null;
        });
        $big = tempnam(sys_get_temp_dir(), 'av-big-');
        $this->files[] = $big;
        $handle = fopen($big, 'r+');
        ftruncate($handle, ClamdScanner::MAX_STREAM_BYTES + 1);   // arquivo esparso: não ocupa o disco
        fclose($handle);
        self::assertSame(ScanStatus::ERROR, $scanner->scan($big));
        self::assertSame(0, $calls, 'Maior que o limite do INSTREAM: nem conecta.');
        self::assertGreaterThanOrEqual(20971520, ClamdScanner::MAX_STREAM_BYTES, 'Cabe o maior arquivo do Disco Virtual.');
        self::assertLessThanOrEqual(60, ClamdScanner::TOTAL_TIMEOUT);

        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret('stream: OK'), 'Sem o \0 final, a resposta está cortada.');
        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret("stream: Eicar FOUND"), 'Cortada também no infectado.');
        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret("stream: OK\nstream: OK\0"), 'Mais de uma linha.');
        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret("\0stream: OK\0"));
        self::assertSame(ScanStatus::CLEAN, ClamdScanner::interpret("stream: OK\0lixo"), 'Só vale até o primeiro \0.');
        self::assertSame(ScanStatus::INFECTED, ClamdScanner::interpret("stream: Win.Test.EICAR_HDB-1 FOUND\0"));
    }

    public function testInfectedFilesCannotBeDownloadedAndScanStatusIsServerOnly(): void
    {
        self::assertFalse(VirtualDisk::downloadable(ScanStatus::INFECTED));
        self::assertFalse(VirtualDisk::downloadable(ScanStatus::ERROR));
        self::assertTrue(VirtualDisk::downloadable(ScanStatus::CLEAN));
        self::assertTrue(VirtualDisk::downloadable(ScanStatus::UNSCANNED));

        $service = (string)file_get_contents(self::ROOT . '/source/Services/Erp/VirtualDisk.php');
        self::assertStringContainsString('if (!self::downloadable(', $service, 'file() confere antes de devolver o caminho.');
        self::assertStringNotContainsString("\$input['scan_status']", $service, 'O status não vem do formulário.');
        self::assertSame(0, preg_match('/UPDATE\s+erp_documents[^;]*scan_status/i', $service), 'Nenhum UPDATE muda o status depois do upload.');
        self::assertSame(1, preg_match_all("/'ss' => \\\$scanStatus/", $service), 'O INSERT grava o resultado da política, e não um valor de entrada.');
        $controller = (string)file_get_contents(self::ROOT . '/source/Controllers/Api/V1/VirtualDisk.php');
        self::assertStringContainsString("'file_blocked'", $controller);
        self::assertStringNotContainsString('scan_status', $controller, 'A API não aceita o status.');
        $definitions = (string)file_get_contents(self::ROOT . '/source/Services/Erp/Registry/Definitions.php');
        self::assertStringNotContainsString("'erp_documents'", $definitions, 'Nenhum cadastro genérico edita os arquivos.');
    }
}
