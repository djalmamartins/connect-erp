<?php
declare(strict_types=1);

namespace Source\Services\Talk\Jack;

/**
 * Classificador por regras da Jack (ADR 0004). Intenções fechadas; nada fora desta lista.
 * Cada termo tem um peso. Só é "confiante" quando a intenção vencedora tem pontos suficientes
 * e folga sobre a segunda colocada (contando o setor, já que boleto também é Financeiro).
 */
final class Classifier
{
    /** intenção => setor (nome normalizado, sem acento) */
    public const SECTOR = [
        'boleto' => 'financeiro',
        'financeiro' => 'financeiro',
        'cotacao' => 'administrativo',
        'administrativo' => 'administrativo',
        'manutencao' => 'manutencao',
    ];

    /** Termos já normalizados (minúsculas, sem acento). Expressões regulares sem delimitador. */
    private const RULES = [
        'boleto' => [
            'boleto' => 3, '2a via' => 3, 'segunda via' => 3, '2 via' => 3, 'linha digitavel' => 3,
            'codigo de barras' => 3, 'pix' => 2, 'condominio do mes' => 2,
        ],
        'financeiro' => [
            'pagamento' => 2, 'paguei' => 2, 'pagar' => 2, 'taxa' => 2, 'cobranca' => 2, 'multa' => 2,
            'juros' => 2, 'acordo' => 2, 'parcel\w*' => 2, 'debito' => 2, 'inadimpl\w*' => 2, 'reembolso' => 2,
            'valor' => 1, 'fatura' => 2, 'recibo' => 1, 'nota fiscal' => 1, 'quitacao' => 2, 'atrasad\w*' => 1,
        ],
        'cotacao' => [
            'cotacao' => 3, 'cotar' => 3, 'orcamento' => 3, 'orcar' => 3, 'proposta' => 2,
            'quanto custa' => 2, 'preco' => 2, 'contratar' => 1, 'administrar (o|nosso|meu) condominio' => 3,
        ],
        'administrativo' => [
            'assembleia' => 3, 'ata' => 2, 'reserva\w*' => 2, 'salao de festa\w*' => 2, 'churrasqueira' => 2,
            'mudanca' => 2, 'documento\w*' => 2, 'declaracao' => 2, 'regimento' => 2, 'convencao' => 2,
            'cadastro' => 2, 'sindic\w*' => 1, 'advertencia' => 2, 'reclamacao' => 1, 'barulho' => 2,
            'vizinho' => 1, 'autorizacao' => 1, 'tag' => 1, 'controle remoto' => 1, 'certidao' => 2,
        ],
        'manutencao' => [
            'falta (de )?agua' => 3, 'faltando agua' => 3, 'sem agua' => 3, 'acabou a agua' => 3, 'agua (acabou|cortada|parou)' => 3,
            'falta (de )?(luz|energia)' => 3, 'sem (luz|energia)' => 3, 'queda de (luz|energia)' => 3, 'acabou a (luz|energia)' => 3,
            'cano' => 2, 'torneira' => 2, 'descarga' => 2, 'ralo' => 2, 'pressao da agua' => 3, 'agua fraca' => 3,
            'vazamento' => 3, 'vazando' => 3, 'infiltracao' => 3, 'goteira' => 3, 'agua( \w+){0,2} no chao' => 3,
            'agua' => 1, 'molhad\w*' => 1, 'entupi\w*' => 3, 'esgoto' => 3, 'elevador' => 3, 'portao' => 3,
            'interfone' => 3, 'lampada' => 3, 'luz' => 1, 'queimad\w*' => 2, 'quebrad\w*' => 2, 'quebrou' => 2,
            'estragad\w*' => 2, 'nao funciona' => 2, 'parou de funcionar' => 2, 'parado' => 1, 'conserto' => 2,
            'consertar' => 2, 'reparo' => 2, 'manutencao' => 3, 'bomba' => 2, 'piscina' => 1, 'garagem' => 1,
            'corredor' => 1, 'escada' => 1, 'camera' => 2, 'cerca eletrica' => 3, 'curto' => 2, 'fio\w*' => 1,
            'rachadura' => 3, 'telhado' => 2, 'caixa d.?agua' => 2, 'gas' => 2, 'cheiro' => 1, 'barata\w*' => 2,
            'dedetiza\w*' => 2, 'limpeza' => 1, 'sujeira' => 1, 'lixo' => 1, 'jardim' => 1, 'poda' => 2,
        ],
    ];

    public function __construct(private int $minScore = 3, private float $margin = 2.0)
    {
    }

    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ª' => 'a', 'º' => 'o']);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * @return array{intent:?string, sector:?string, confident:bool, scores:array<string,int>, matched:list<string>}
     */
    public function classify(string $text): array
    {
        $t = ' ' . self::normalize($text) . ' ';
        $scores = [];
        $matched = [];
        foreach (self::RULES as $intent => $terms) {
            foreach ($terms as $term => $weight) {
                if (preg_match('/(?<![\p{L}\d])' . $term . '(?![\p{L}\d])/u', $t, $m)) {
                    $scores[$intent] = ($scores[$intent] ?? 0) + $weight;
                    $matched[] = trim($m[0]);
                }
            }
        }
        if ($scores === []) {
            return ['intent' => null, 'sector' => null, 'confident' => false, 'scores' => [], 'matched' => []];
        }
        arsort($scores);
        $intent = (string) array_key_first($scores);
        $sector = self::SECTOR[$intent];

        // folga medida por setor: boleto + financeiro somam para o mesmo setor
        $bySector = [];
        foreach ($scores as $i => $s) {
            $bySector[self::SECTOR[$i]] = ($bySector[self::SECTOR[$i]] ?? 0) + $s;
        }
        arsort($bySector);
        $top = (int) reset($bySector);
        $second = (int) (array_values($bySector)[1] ?? 0);
        if (array_key_first($bySector) !== $sector) {
            // a intenção mais forte não é do setor mais forte: fica com o setor e a melhor intenção dele
            $sector = (string) array_key_first($bySector);
            foreach ($scores as $i => $s) {
                if (self::SECTOR[$i] === $sector) {
                    $intent = $i;
                    break;
                }
            }
        }
        $confident = $top >= $this->minScore && ($second === 0 || $top >= $second * $this->margin);
        return ['intent' => $intent, 'sector' => $sector, 'confident' => $confident, 'scores' => $scores, 'matched' => array_values(array_unique($matched))];
    }
}
