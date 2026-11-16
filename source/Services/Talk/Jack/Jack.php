<?php
declare(strict_types=1);

namespace Source\Services\Talk\Jack;

use Source\Services\Talk\Support\Db;

/**
 * Jack (Jaqueline): faz a triagem de toda conversa nova, sem menu de números.
 *  - Assunto claro na mensagem → vai para a fila do setor na hora (sem esperar).
 *  - Assunto ainda não claro → espera a pessoa parar de escrever ($waitSeconds) e lê tudo o que ela mandou.
 *  - Assunto claro → abre o chamado no setor certo, EM SILÊNCIO (ADR 0004: direcionar não gera
 *    mensagem ao cliente). Quem fala com a pessoa é o atendente do setor.
 *  - Só cumprimentou ("bom dia") → não responde; espera a pessoa dizer o assunto.
 *  - Falou algo que ela não reconhece, ou passou $maxWaitSeconds → setor padrão (Administrativo).
 *  - mode "off": sem triagem; tudo cai direto no setor padrão.
 * Só usa textos aprovados (Service\Texts).
 */
final class Jack
{
    public const NAME = 'Jack';
    private const GREETING = '/^(oi+|ola|ole|bom dia|boa tarde|boa noite|tudo bem|td bem|tudo bom|e ai|opa|ei|alo|hello|hi|blz|beleza|ok|obrigad[oa]|por favor|pf|\?|\.|!|,|\s)+$/u';

    public function __construct(
        private Db $db,
        private Classifier $classifier = new Classifier(),
        private string $mode = 'auto',
        private int $waitSeconds = 5,
        private int $maxWaitSeconds = 30,
        private string $fallbackKey = 'administrativo',
        private int $typingBaseSeconds = 20,
    ) {
        if (!in_array($mode, ['off', 'auto'], true)) {
            $this->mode = 'auto'; // "shadow" (fase J1) virou "auto" quando o menu saiu
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** Quanto tempo ela "digita" antes de mandar: base + o tamanho do texto, até 90 s. */
    public function typingSeconds(string $text): int
    {
        return $this->typingBaseSeconds <= 0 ? 0 : min(90, $this->typingBaseSeconds + intdiv(mb_strlen($text), 8));
    }

    /** O assunto já está claro o bastante para ir para a fila sem esperar a pessoa terminar? */
    public function isClear(string $text): bool
    {
        if ($this->mode === 'off') {
            return true;
        }
        $r = $this->classifier->classify($text);
        return $r['sector'] !== null && $r['confident'];
    }

    /** A pessoa parou de escrever (ou já esperou demais)? */
    public function isDue(int $quietSeconds, int $ageSeconds): bool
    {
        return $quietSeconds >= $this->waitSeconds || $ageSeconds >= $this->maxWaitSeconds;
    }

    /**
     * Decide o que fazer com uma conversa na triagem, lendo tudo o que a pessoa escreveu.
     * @return array{action:string, sector:?array}|null  action = route | fallback; null = esperar
     */
    public function decide(array $conv, string $text, int $quietSeconds, int $ageSeconds): ?array
    {
        $r = $this->classifier->classify($text);
        $sector = $r['sector'] !== null && $r['confident'] ? $this->sectorByKey($r['sector']) : null;
        if ($sector !== null) {
            $this->record($conv, $sector, $r['intent'], $r);
            return ['action' => 'route', 'sector' => $sector];
        }
        $onlyGreeting = (bool) preg_match(self::GREETING, Classifier::normalize($text));
        if ($onlyGreeting && $ageSeconds < $this->maxWaitSeconds) {
            return null; // só cumprimentou: espera calada a pessoa contar o assunto
        }
        $fallback = $this->fallbackSector();
        if ($fallback === null) {
            return null;
        }
        $this->record($conv, $fallback, null, $r);
        return ['action' => 'fallback', 'sector' => $fallback];
    }

    /** Registra a decisão (gabarito: final_sector_id é atualizado se um humano transferir). */
    public function record(array $conv, array $sector, ?string $intent, ?array $r): void
    {
        $this->db->insert('talk_jack_decisions', [
            'conversation_id' => $conv['id'],
            'message_id' => (int) ($this->db->one("SELECT MAX(id) id FROM talk_messages WHERE conversation_id = ? AND direction = 'in'", [$conv['id']])['id'] ?? 0),
            'mode' => 'auto',
            'intent' => $intent,
            'suggested_sector_id' => $sector['id'],
            'confident' => $r !== null && $r['confident'] ? 1 : 0,
            'scores' => mb_substr(json_encode($r['scores'] ?? [], JSON_UNESCAPED_UNICODE), 0, 255),
            'matched' => !empty($r['matched']) ? mb_substr(implode(', ', $r['matched']), 0, 255) : null,
            'action' => 'routed',
            'final_sector_id' => $sector['id'],
        ]);
    }

    public function fallbackSector(): ?array
    {
        return $this->sectorByKey($this->fallbackKey);
    }

    /**
     * Respostas da Jaqueline ao cliente antes de um humano assumir:
     *  - "identity": perguntaram se é robô/IA → ela se apresenta (nunca nega).
     *  - "scope": depois disso, a pessoa seguiu perguntando algo que ela não sabe → passa para o setor
     *    (Administrativo se ainda não tem setor). Cada uma no máximo uma vez por conversa.
     * @return array{kind:string, sector:?array}|null
     */
    public function converse(array $conv, string $text): ?array
    {
        if ($this->mode === 'off' || !in_array($conv['status'], ['bot', 'pending'], true) || $conv['assigned_user_id'] !== null) {
            return null;
        }
        $sent = array_column($this->db->all(
            "SELECT DISTINCT type FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND type IN ('jack_identity','jack_scope')",
            [$conv['id']]
        ), 'type');
        if (self::asksIfBot($text)) {
            return in_array('jack_identity', $sent, true) ? null : ['kind' => 'identity', 'sector' => null];
        }
        if (!in_array('jack_identity', $sent, true) || in_array('jack_scope', $sent, true)) {
            return null;
        }
        $n = Classifier::normalize($text);
        if (preg_match('/^\d{1,2}$/', $n) || $this->classifier->classify($text)['confident']) {
            return null; // escolheu opção do menu ou disse claramente o assunto: segue o fluxo normal
        }
        $sector = $conv['sector_id'] !== null
            ? $this->db->one('SELECT id, name FROM talk_sectors WHERE id = ?', [$conv['sector_id']])
            : $this->sectorByKey('administrativo');
        return $sector ? ['kind' => 'scope', 'sector' => $sector] : null;
    }

    public static function asksIfBot(string $text): bool
    {
        $t = Classifier::normalize($text);
        return (bool) preg_match(
            '/(?<![\p{L}])(robo|robozinho|bot|chatbot|inteligencia artificial)(?![\p{L}])'
            . '|(?<![\p{L}])(voce|vc|ce|tu|isso|aqui) (e|eh) (um |uma |a |o )?(ia|maquina|automatico|automatica|gravacao)(?![\p{L}])'
            . '|(pessoa|humano|gente) de verdade|pessoa real|(falando|conversando) com (uma |um )?(pessoa|humano|maquina)'
            . '|(e|eh) (uma )?pessoa\s*\?|(e|eh) humano/u',
            $t
        );
    }

    /** Guarda onde a conversa realmente ficou: é o gabarito dos acertos. */
    public function settle(int $conversationId, int $sectorId): void
    {
        $this->db->run('UPDATE talk_jack_decisions SET final_sector_id = ? WHERE conversation_id = ?', [$sectorId, $conversationId]);
    }

    /**
     * Acertos por conversa: compara a primeira sugestão confiante com o setor final.
     * @return array{conversas:int, com_sugestao:int, acertos:int, erros:int, taxa:?float, por_setor:list<array>}
     */
    public function stats(int $days = 30): array
    {
        $rows = $this->db->all(
            "SELECT d.conversation_id, d.suggested_sector_id, d.final_sector_id, s.name
               FROM talk_jack_decisions d
               JOIN (SELECT conversation_id, MIN(id) AS id FROM talk_jack_decisions
                      WHERE confident = 1 AND suggested_sector_id IS NOT NULL AND created_at >= NOW() - INTERVAL ? DAY
                      GROUP BY conversation_id) f ON f.id = d.id
               LEFT JOIN talk_sectors s ON s.id = d.suggested_sector_id",
            [$days]
        );
        $total = (int) ($this->db->one('SELECT COUNT(DISTINCT conversation_id) n FROM talk_jack_decisions WHERE created_at >= NOW() - INTERVAL ? DAY', [$days])['n'] ?? 0);
        $hits = $misses = 0;
        $per = [];
        foreach ($rows as $r) {
            if ($r['final_sector_id'] === null) {
                continue; // ainda sem setor final
            }
            $ok = (int) $r['final_sector_id'] === (int) $r['suggested_sector_id'];
            $ok ? $hits++ : $misses++;
            $per[$r['name']] ??= ['setor' => $r['name'], 'acertos' => 0, 'erros' => 0];
            $per[$r['name']][$ok ? 'acertos' : 'erros']++;
        }
        $judged = $hits + $misses;
        return ['modo' => $this->mode, 'conversas' => $total, 'com_sugestao' => count($rows), 'acertos' => $hits, 'erros' => $misses,
            'taxa' => $judged ? round($hits / $judged, 3) : null, 'por_setor' => array_values($per)];
    }

    private function sectorByKey(string $key): ?array
    {
        // setor marcado para a Jack (pode ter sido renomeado na tela Equipe e setores)
        $marked = $this->db->one('SELECT id, name FROM talk_sectors WHERE active = 1 AND jack_key = ?', [$key]);
        if ($marked) {
            return $marked;
        }
        foreach ($this->db->all('SELECT id, name FROM talk_sectors WHERE active = 1') as $s) {
            if (str_replace(' ', '', Classifier::normalize($s['name'])) === $key) {
                return $s;
            }
        }
        return null;
    }

}
