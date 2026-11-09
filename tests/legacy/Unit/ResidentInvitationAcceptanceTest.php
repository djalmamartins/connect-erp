<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ResidentInvitationAcceptance as Acceptance;

/** Aceite do convite (ADR 0092): token, mensagem genérica, senha, documento e limite sem o token. */
final class ResidentInvitationAcceptanceTest extends TestCase
{
    public function testInvalidTokensAllGetTheSameGenericAnswerWithoutTouchingTheDatabase(): void
    {
        $service = new Acceptance(new PDO('sqlite::memory:'));   // sem tabelas: se consultasse o banco, falharia
        foreach ([null, '', 'abc', str_repeat('z', 64), strtoupper(str_repeat('a', 64)), ['x'], str_repeat('a', 65)] as $token) {
            self::assertNull($service->preview($token));
            self::assertSame(['ok' => false, 'message' => Acceptance::GENERIC], $service->accept($token, '52998224725', 'SenhaBoa123', 'SenhaBoa123'));
        }
    }

    public function testPasswordRules(): void
    {
        self::assertNull(Acceptance::passwordProblem('SenhaBoa123', 'SenhaBoa123'));
        self::assertNotNull(Acceptance::passwordProblem('curta', 'curta'));
        self::assertNotNull(Acceptance::passwordProblem('SenhaBoa123', 'SenhaBoa124'));
        $hash = password_hash('x', PASSWORD_DEFAULT);
        self::assertNotNull(Acceptance::passwordProblem($hash, $hash), 'Valor com formato de hash é recusado.');
    }

    public function testNameAndDocument(): void
    {
        self::assertSame(['Ana', 'Maria Souza'], Acceptance::splitName('  Ana   Maria Souza '));
        self::assertSame(['Caio', 'Caio'], Acceptance::splitName('Caio'));
        self::assertTrue(Acceptance::documentMatches('52998224725', '529.982.247-25'));
        self::assertFalse(Acceptance::documentMatches('52998224725', '11144477735'));
        self::assertTrue(Acceptance::documentMatches(null, '111.444.777-35'), 'Cadastro sem documento aceita o informado.');
        self::assertFalse(Acceptance::documentMatches(null, ''));
    }

    public function testThrottleSubjectsNeverCarryTheToken(): void
    {
        $token = str_repeat('ab', 32);
        $subjects = Acceptance::subjects('203.0.113.9', $token);
        self::assertSame(['ip', 'token'], array_keys($subjects));
        self::assertStringNotContainsString($token, implode('', $subjects));
        self::assertStringNotContainsString('203.0.113.9', implode('', $subjects));
        self::assertSame(['token'], array_keys(Acceptance::subjects('nao-e-ip', $token)));
    }
}
