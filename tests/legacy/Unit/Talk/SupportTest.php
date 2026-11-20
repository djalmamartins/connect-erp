<?php
declare(strict_types=1);

namespace MovesOSTests\Unit\Talk;

use Source\Services\Talk\Support\Phone;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_normaliza_telefone(): void
    {
        $this->assertSame('5531999998888', Phone::normalize('(31) 99999-8888'));
        $this->assertSame('5531999998888', Phone::normalize('5531999998888@s.whatsapp.net'));
        $this->assertSame('5531999998888', Phone::normalize('+55 31 99999-8888'));
    }

    public function test_variacoes_com_e_sem_nono_digito(): void
    {
        $c = Phone::candidates('5531999998888');
        $this->assertContains('31999998888', $c);
        $this->assertContains('3199998888', $c);
        $this->assertContains('553199998888', $c);
        $this->assertContains('31999998888', Phone::candidates('553199998888'));
    }

}
