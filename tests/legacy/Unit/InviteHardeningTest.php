<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\InvitationRules;
use Source\Services\Erp\InviteThrottle;
use Source\Services\Erp\ResidentInvitationAcceptance as Acceptance;

/** Endurecimento do convite (ADR 0093): limite que falha fechado e documento obrigatório. */
final class InviteHardeningTest extends TestCase
{
    public function testThrottleUnavailableRefuses(): void
    {
        $broken = new PDO('sqlite::memory:');   // sem a tabela auth_throttle_events: leitura e gravação falham
        $throttle = new InviteThrottle($broken);
        $subjects = InviteThrottle::subjects('203.0.113.9', str_repeat('ab', 32));
        self::assertSame(InviteThrottle::UNAVAILABLE, $throttle->check($subjects), 'Sem ler a tabela, não libera.');
        self::assertFalse($throttle->fail($subjects), 'Sem gravar, avisa quem chama.');
        $result = (new Acceptance($broken))->accept(str_repeat('ab', 32), '52998224725', 'SenhaBoa123', 'SenhaBoa123', '203.0.113.9');
        self::assertSame(['ok' => false, 'message' => InviteThrottle::UNAVAILABLE_MESSAGE], $result, 'O aceite é recusado com "tente mais tarde".');
        self::assertSame(['ip'], array_keys(InviteThrottle::subjects('203.0.113.9', null)), 'Abrir conta só o IP.');
    }

    public function testInvitationNeedsAValidDocument(): void
    {
        self::assertTrue(InvitationRules::validDocument('529.982.247-25'));
        self::assertTrue(InvitationRules::validDocument('11.222.333/0001-81'));
        foreach ([null, '', '12345678900', '11111111111', '123', '11.222.333/0001-82'] as $bad) {
            self::assertFalse(InvitationRules::validDocument($bad), var_export($bad, true));
        }
        InvitationRules::assertInvitable([1 => '52998224725', 2 => '11222333000181']);
        try {
            InvitationRules::assertInvitable([1 => '52998224725', 7 => null, 9 => '123']);
            self::fail('Convidou morador sem documento.');
        } catch (InvalidArgumentException $error) {   // o controller responde 422
            self::assertStringStartsWith(InvitationRules::NO_DOCUMENT, $error->getMessage());
            self::assertStringContainsString('7, 9', $error->getMessage());
        }
    }

    public function testAcceptNeverSkipsTheDocument(): void
    {
        self::assertFalse(InvitationRules::documentMatches(null, '52998224725'), 'Cadastro sem documento não aceita.');
        self::assertFalse(InvitationRules::documentMatches('12345678900', '12345678900'), 'Documento inválido no cadastro não aceita.');
        self::assertFalse(InvitationRules::documentMatches('52998224725', ''));
        self::assertTrue(InvitationRules::documentMatches('52998224725', '529.982.247-25'));
    }
}
