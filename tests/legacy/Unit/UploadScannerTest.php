<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Antivirus\ClamdScanner;
use Source\Services\Erp\Antivirus\NullScanner;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Services\Erp\Antivirus\UploadScanner;
use Source\Services\Erp\Antivirus\UploadScanPolicy;
use Source\Services\Erp\Antivirus\UploadScanUnavailable;

/** Antivírus no upload (ADR 0098): cliente INSTREAM do clamd contra um socket falso e a política off/enforce. */
final class UploadScannerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!in_array(FakeClamdStream::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(FakeClamdStream::SCHEME, FakeClamdStream::class);
        }
        FakeClamdStream::$mode = 'scan';
        FakeClamdStream::$received = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testCleanFileFollowsTheInstreamProtocol(): void
    {
        $content = str_repeat('Ata da assembleia. ', 9000);   // passa de um bloco (CHUNK)
        self::assertGreaterThan(ClamdScanner::CHUNK, strlen($content));
        self::assertSame(ScanStatus::CLEAN, $this->scanner()->scan($this->file($content)));
        self::assertSame($content, FakeClamdStream::$received, 'O clamd recebeu o arquivo inteiro, em blocos, com o bloco zero no fim.');
    }

    public function testEicarSignatureIsInfected(): void
    {
        self::assertSame(ScanStatus::INFECTED, $this->scanner()->scan($this->file(self::eicar())));
    }

    public function testTimeoutAndConnectionFailureAreErrors(): void
    {
        FakeClamdStream::$mode = 'silent';   // não responde: a leitura volta vazia, como no timeout
        self::assertSame(ScanStatus::ERROR, $this->scanner()->scan($this->file('texto')));
        FakeClamdStream::$mode = 'garbage';
        self::assertSame(ScanStatus::ERROR, $this->scanner()->scan($this->file('texto')));
        $down = new ClamdScanner('unix:///nao/existe.sock', static fn () => null);
        self::assertSame(ScanStatus::ERROR, $down->scan($this->file('texto')));
        $closedPort = new ClamdScanner('127.0.0.1:1');   // nada escutando: conexão recusada dentro do timeout curto
        self::assertSame(ScanStatus::ERROR, $closedPort->scan($this->file('texto')));
    }

    public function testEndpointIsReadFromTheEnvironmentAndValidated(): void
    {
        self::assertFalse((new ClamdScanner(''))->available(), 'Sem ERP_AV_ENDPOINT o adaptador fica desligado.');
        self::assertSame(ScanStatus::ERROR, (new ClamdScanner(''))->scan($this->file('texto')));
        self::assertSame('unix:///var/run/clamav/clamd.ctl', ClamdScanner::address('unix:///var/run/clamav/clamd.ctl'));
        self::assertSame('tcp://clamav:3310', ClamdScanner::address('clamav:3310'));
        self::assertSame('tcp://10.0.0.5:3310', ClamdScanner::address('tcp://10.0.0.5:3310'));
        foreach (['http://clamav:3310', 'clamav', 'clamav:0', 'clamav:70000', 'unix://relativo.sock', 'a b:3310'] as $bad) {
            self::assertNull(ClamdScanner::address($bad), $bad);
            self::assertFalse((new ClamdScanner($bad))->available(), $bad);
        }
        self::assertSame(ScanStatus::CLEAN, ClamdScanner::interpret("stream: OK\0"));
        self::assertSame(ScanStatus::INFECTED, ClamdScanner::interpret("stream: Win.Test.EICAR_HDB-1 FOUND\0"));
        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret("INSTREAM size limit exceeded. ERROR\0"));
        self::assertSame(ScanStatus::ERROR, ClamdScanner::interpret(''));
    }

    public function testPolicyOffEnforceAndFailClosed(): void
    {
        $path = $this->file('texto');
        self::assertSame(UploadScanPolicy::MODE_OFF, UploadScanPolicy::mode(''));
        self::assertSame(UploadScanPolicy::MODE_OFF, UploadScanPolicy::mode(' OFF '));
        foreach (['enforce', 'ENFORCE', 'on', 'enforcee'] as $raw) {
            self::assertSame(UploadScanPolicy::MODE_ENFORCE, UploadScanPolicy::mode($raw), 'Valor desconhecido não desliga: ' . $raw);
        }

        $spy = new class implements UploadScanner {
            public int $calls = 0;
            public function scan(string $path): string { $this->calls++; return ScanStatus::INFECTED; }
            public function available(): bool { return true; }
        };
        self::assertSame(ScanStatus::UNSCANNED, (new UploadScanPolicy('off', $spy))->check($path), 'Modo off aceita como não verificado.');
        self::assertSame(0, $spy->calls, 'Modo off nem chama o antivírus.');

        self::assertSame(ScanStatus::CLEAN, (new UploadScanPolicy('enforce', $this->scanner()))->check($path));
        try {
            (new UploadScanPolicy('enforce', $this->scanner()))->check($this->file(self::eicar()));
            self::fail('Aceitou arquivo infectado.');
        } catch (InvalidArgumentException $error) {   // o controller responde 422
            self::assertSame(UploadScanPolicy::INFECTED_MESSAGE, $error->getMessage());
        }
        foreach ([new NullScanner(), new ClamdScanner(''), new ClamdScanner('unix:///nao/existe.sock', static fn () => null)] as $down) {
            try {
                (new UploadScanPolicy('enforce', $down))->check($path);
                self::fail('Modo enforce aceitou com o antivírus fora do ar: ' . $down::class);
            } catch (UploadScanUnavailable $error) {   // o controller responde 503
                self::assertSame(UploadScanPolicy::UNAVAILABLE_MESSAGE, $error->getMessage());
            }
        }
        FakeClamdStream::$mode = 'silent';
        $this->expectException(UploadScanUnavailable::class);
        (new UploadScanPolicy('enforce', $this->scanner()))->check($path);
    }

    private function scanner(): ClamdScanner
    {
        return new ClamdScanner('unix:///falso/clamd.sock', static fn (string $endpoint) => fopen(FakeClamdStream::SCHEME . '://clamd', 'r+'));
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'av-');
        file_put_contents($path, $content);
        return $this->files[] = $path;
    }

    /** Arquivo de teste EICAR, montado só em memória para o próprio fonte não parecer vírus. */
    private static function eicar(): string
    {
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-' . 'TEST-FILE!$H+H*';
    }
}

/**
 * clamd falso: lê o INSTREAM inteiro (comando, blocos com tamanho big-endian e o bloco zero) e responde
 * como o clamd. Modos: scan (OK, ou FOUND se tiver a assinatura EICAR), silent (não responde) e garbage.
 */
final class FakeClamdStream
{
    public const SCHEME = 'fakeclamd';
    public static string $mode = 'scan';
    public static ?string $received = null;
    /** @var resource|null */
    public $context;
    private string $buffer = '';
    private ?string $reply = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->buffer .= $data;
        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        $this->reply ??= $this->answer();
        $part = substr($this->reply, 0, $count);
        $this->reply = (string)substr($this->reply, strlen($part));
        return $part;
    }

    public function stream_eof(): bool
    {
        return $this->reply === '';
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
    }

    private function answer(): string
    {
        if (self::$mode === 'silent') {
            return '';
        }
        if (self::$mode === 'garbage') {
            return "???\0";
        }
        if (!str_starts_with($this->buffer, "zINSTREAM\0")) {
            return "UNKNOWN COMMAND\0";
        }
        $offset = strlen("zINSTREAM\0");
        $data = '';
        while (true) {
            if (strlen($this->buffer) < $offset + 4) {
                return "INSTREAM: incomplete. ERROR\0";
            }
            $length = unpack('N', substr($this->buffer, $offset, 4))[1];
            $offset += 4;
            if ($length === 0) {
                break;
            }
            $data .= substr($this->buffer, $offset, $length);
            $offset += $length;
        }
        self::$received = $data;
        return str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0";
    }
}
