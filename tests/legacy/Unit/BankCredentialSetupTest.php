<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeInterHttp;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\ZipEntries;

/** Pacote do banco enviado pela tela (ADR 0158): ZIP em memória, certificado e chave achados pelo conteúdo, diagnóstico do teste. */
final class BankCredentialSetupTest extends TestCase
{
    public function testZipStoredAndDeflatedAreRead(): void
    {
        foreach ([true, false] as $deflate) {
            $entries = ZipEntries::read(FakeInterHttp::zip(['pasta/Inter API_Certificado.crt' => 'CERT', 'Inter API_Chave.key' => 'KEY'], $deflate));
            self::assertSame([['name' => 'Inter API_Certificado.crt', 'content' => 'CERT'], ['name' => 'Inter API_Chave.key', 'content' => 'KEY']], $entries);
        }
    }

    public function testZipBombAndCorruptionAreRefused(): void
    {
        $big = FakeInterHttp::zip(['grande.crt' => str_repeat('A', ZipEntries::MAX_ENTRY_BYTES + 1)]);
        try {
            ZipEntries::read($big);
            self::fail('Entrada grande demais deveria ser recusada.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('grande demais', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        ZipEntries::read(substr(FakeInterHttp::zip(['a.crt' => 'x']), 0, 20));
    }

    public function testCrcMismatchIsRefused(): void
    {
        $zip = FakeInterHttp::zip(['a.crt' => 'conteudo'], false);
        $zip = str_replace('conteudo', 'CONTEUDO', $zip);
        $this->expectException(InvalidArgumentException::class);
        ZipEntries::read($zip);
    }

    public function testExtractFindsPairByContentInZipOrLooseFiles(): void
    {
        [$cert, $key] = FakeInterHttp::certificatePair();
        $zip = FakeInterHttp::zip(['leia-me.txt' => 'instruções', 'a.bin' => $key, 'b.bin' => $cert]);
        $pair = BankCredentialSetup::extract([['name' => 'pacote.zip', 'content' => base64_encode($zip)]]);
        self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $pair['certificate']);
        self::assertStringContainsString('PRIVATE KEY-----', $pair['private_key']);

        $loose = BankCredentialSetup::extract([['name' => 'x.crt', 'content' => base64_encode($cert)], ['name' => 'x.key', 'content' => base64_encode($key)]]);
        self::assertSame($pair, $loose);
    }

    public function testExtractExplainsWhatIsMissing(): void
    {
        [$cert] = FakeInterHttp::certificatePair();
        foreach ([
            [[], 'Envie o ZIP'],
            [[['name' => 'x.crt', 'content' => base64_encode($cert)]], 'chave privada'],
            [[['name' => 'x.key', 'content' => base64_encode("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----")]], 'certificado'],
            [[['name' => 'x.key', 'content' => base64_encode("-----BEGIN ENCRYPTED PRIVATE KEY-----\nabc\n-----END ENCRYPTED PRIVATE KEY-----")]], 'senha'],
            [[['name' => 'x.crt', 'content' => 'não é base64!']], 'Não consegui ler'],
        ] as [$files, $expected]) {
            try {
                BankCredentialSetup::extract($files);
                self::fail("Deveria recusar: {$expected}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testDiagnoseTranslatesBankErrors(): void
    {
        self::assertStringContainsString('produção', BankCredentialSetup::diagnose('Sem resposta do Inter: TLS connect error: error:0A000418:SSL routines::tlsv1 alert unknown ca'));
        self::assertStringContainsString('client ID ou o secret', BankCredentialSetup::diagnose('Inter recusou a autenticação (401). Confira client_id, client_secret e certificado.'));
        self::assertStringContainsString('8h às 20h', BankCredentialSetup::diagnose('Sem resposta do Inter: Connection timed out'));
        self::assertStringContainsString('outra coisa', BankCredentialSetup::diagnose('outra coisa'));
    }
}
