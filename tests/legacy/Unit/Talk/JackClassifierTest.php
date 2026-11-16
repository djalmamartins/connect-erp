<?php
declare(strict_types=1);

namespace MovesOSTests\Unit\Talk;

use Source\Services\Talk\Jack\Classifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JackClassifierTest extends TestCase
{
    public static function exemplos(): array
    {
        return [
            'água no corredor' => ['Passei aqui no corredor e tem uma água aqui no chão', 'manutencao', 'manutencao'],
            'boleto' => ['Bom dia, estava precisando do meu boleto', 'boleto', 'financeiro'],
            'segunda via sem acento' => ['preciso da 2a via do condominio', 'boleto', 'financeiro'],
            'cotação' => ['Gostaria de um orçamento para administrar o nosso condomínio', 'cotacao', 'administrativo'],
            'assembleia' => ['Quando vai ser a assembleia? Quero ver a ata', 'administrativo', 'administrativo'],
            'elevador' => ['O ELEVADOR do bloco B parou de funcionar', 'manutencao', 'manutencao'],
            'acordo' => ['Quero fazer um acordo da taxa atrasada, dá pra parcelar?', 'financeiro', 'financeiro'],
            'portão' => ['portão da garagem quebrado', 'manutencao', 'manutencao'],
        ];
    }

    #[DataProvider('exemplos')]
    public function test_classifica_com_confianca(string $texto, string $intencao, string $setor): void
    {
        $r = (new Classifier())->classify($texto);
        $this->assertTrue($r['confident'], json_encode($r));
        $this->assertSame($intencao, $r['intent']);
        $this->assertSame($setor, $r['sector']);
    }

    public function test_sem_pista_ou_ambiguo_fica_para_humano(): void
    {
        $c = new Classifier();
        $this->assertFalse($c->classify('Oi, bom dia')['confident']);
        $this->assertNull($c->classify('Oi, bom dia')['intent']);
        $this->assertFalse($c->classify('tô tendo problema aqui no condomínio')['confident']);
        // boleto e vazamento juntos: empate entre setores
        $this->assertFalse($c->classify('o boleto veio errado e tem um vazamento no teto')['confident']);
    }

    public function test_palavra_dentro_de_outra_nao_conta(): void
    {
        // "ata" dentro de "batata", "gas" dentro de "gastei"
        $r = (new Classifier())->classify('gastei com batata');
        $this->assertSame([], $r['scores']);
    }
}
