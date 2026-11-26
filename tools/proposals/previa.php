<?php
/**
 * Prévia do PDF da proposta no modelo da Connect (ADR 0650), com dados fictícios e sem banco.
 * Uso: php tools/proposals/previa.php [arquivo.pdf] [syndic|administrator]
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$out = $argv[1] ?? dirname(__DIR__, 2) . '/.local/proposta-previa.pdf';
@mkdir(dirname($out), 0775, true);
$fill = static function (string $class, array $data): object {
    $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    Closure::bind(function () use ($data): void { $this->data = (object)$data; }, $object, $class)();
    return $object;
};
$person = ['name' => 'Maria Fictícia', 'condominium' => 'Residencial Exemplo - Bloco 08'];
$proposal = $fill(Source\Models\Proposal\Proposal::class, $person + ['id' => 1, 'protocol' => 'PROP-261126-A1B2C3', 'email' => 'maria@exemplo.test', 'units' => 24]);
$defaults = Source\Services\Proposals\ProposalComposer::defaults($person, $argv[2] ?? 'syndic');
$defaults['commercial_terms'] = "Valor da proposta para serviço de administradora: R$ 416,00/mês (quatrocentos e dezesseis reais), reajustado anualmente.";
if (($argv[3] ?? '') === 'longa') {
    $defaults['notes'] = str_repeat("Observação longa de teste para conferir a quebra de página do texto da proposta. ", 60);
}
$response = $fill(Source\Models\Proposal\ProposalResponse::class, $defaults + ['id' => 7, 'created_at' => date('Y-m-d H:i:s')]);
file_put_contents($out, (new Source\Support\Proposal\ProposalPdf())->render($proposal, $response));
echo "Prévia gravada em {$out}\n";
