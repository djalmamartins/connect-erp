<?php
declare(strict_types=1);

namespace Source\Services\Talk\Erp;

use Source\Services\Talk\Contracts\ContactDirectory;
use Source\Services\Talk\TalkHelpdesk;

/**
 * Quem escreveu, direto do cadastro de moradores do ERP (ADR 0323). Mesma busca da rota GET /api/v1/talk/people
 * (TalkHelpdesk::people), sem passar pela rede: variantes do número (com e sem 55 e nono dígito), só cadastros ativos
 * e vigentes de condomínios ativos. O mesmo telefone em mais de um cadastro: usa o mais recente e marca quantos há
 * (a Jack só age sozinha quando há um só). Erro na consulta não para o atendimento: segue sem identificar.
 */
final class LocalContactDirectory implements ContactDirectory
{
    public function __construct(private TalkHelpdesk $erp, private ?\Closure $logger = null)
    {
    }

    public function findByPhone(array $phoneCandidates): ?array
    {
        try {
            $list = $this->erp->people(null, implode(',', array_map('strval', $phoneCandidates)));
        } catch (\Throwable $e) {
            $this->logger && ($this->logger)('Cadastro do ERP indisponível na identificação: ' . $e->getMessage());
            return null;
        }
        if ($list === []) {
            return null;
        }
        $p = $list[0];
        $details = array_filter([
            'vinculo' => $p['relationship'] ?? null,
            'condominio' => $p['condominium']['name'] ?? null,
            'unidade' => $p['unit']['label'] ?? null,
            'condominio_id' => $p['condominium']['id'] ?? null,
            'unidade_id' => $p['unit']['id'] ?? null,
            'email' => $p['email'] ?? null,
            'cadastros' => count($list) > 1 ? count($list) : null,
        ], fn ($v) => $v !== null && $v !== '');
        return ['person_id' => (int) $p['id'], 'name' => (string) $p['name'], 'details' => $details];
    }
}
