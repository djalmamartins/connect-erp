<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\QuickMessages;

final class QuickMessagesTest extends DbTestCase
{
    private QuickMessages $q;
    private StaffMember $sup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->q = new QuickMessages($this->db);
        $this->sup = new StaffMember(1, true);
    }

    public function testSupervisorCreatesGeneralAndEveryoneSeesIt(): void
    {
        $this->q->save($this->sup, ['shortcut' => '/BomDia', 'body' => 'Bom dia, {primeiro_nome}!']);
        $this->q->save($this->sup, ['shortcut' => 'boleto-app', 'body' => 'Pegue no app.', 'sector_id' => $this->sectors['Financeiro']]);

        $fin = $this->q->available($this->agent(2, 'Financeiro'));
        $man = $this->q->available($this->agent(4, 'Manutenção'));
        self::assertSame(['boleto-app', 'bomdia'], array_column($fin, 'shortcut'));
        self::assertSame(['bomdia'], array_column($man, 'shortcut'), 'mensagem do Financeiro não aparece para a Manutenção');
        self::assertFalse($fin[0]['can_edit']);
        self::assertCount(2, $this->q->available($this->sup));
    }

    public function testManagerOnlyManagesOwnSector(): void
    {
        $mgr = new StaffMember(5, false, [], [$this->sectors['Manutenção']]);
        $ok = $this->q->save($mgr, ['shortcut' => 'visita', 'body' => 'Vamos agendar a visita.', 'sector_id' => $this->sectors['Manutenção']]);
        self::assertTrue($this->q->available($mgr)[0]['can_edit']);

        foreach ([null, $this->sectors['Financeiro']] as $sector) {
            try {
                $this->q->save($mgr, ['shortcut' => 'x' . (int) $sector, 'body' => 'oi', 'sector_id' => $sector]);
                self::fail('gerente não pode criar fora do setor');
            } catch (DomainError $e) {
                self::assertSame(403, $e->getCode());
            }
        }
        $this->expectExceptionCode(403);
        $this->q->delete($this->agent(4, 'Manutenção'), $ok['id']);
    }

    public function testValidationAndUpdate(): void
    {
        $a = $this->q->save($this->sup, ['shortcut' => 'ola', 'body' => 'Olá']);
        foreach ([['shortcut' => 'com espaço', 'body' => 'x'], ['shortcut' => 'ok', 'body' => '  '], ['shortcut' => 'ola', 'body' => 'dup']] as $bad) {
            try {
                $this->q->save($this->sup, $bad);
                self::fail('deveria recusar ' . json_encode($bad));
            } catch (DomainError $e) {
                self::assertSame(422, $e->getCode());
            }
        }
        $this->q->save($this->sup, ['shortcut' => 'ola', 'body' => 'Olá, {nome}'], $a['id']);
        self::assertSame('Olá, {nome}', $this->q->available($this->sup)[0]['body']);
        $this->q->delete($this->sup, $a['id']);
        self::assertSame([], $this->q->available($this->sup));
    }
}
