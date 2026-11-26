<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Vigia da hospedagem (ADR 0333), rodado de hora em hora pelo cron (grupo "hora"). Junta o que parou e avisa por e-mail
 * (ALERT_EMAIL). Regras puras:
 *  - problems(): o que precisa de atenção, por chave estável (stale:minuto, failing:hora, notifications), a partir da HostingHealth::evaluate() e da contagem de avisos da fila
 *    que falharam nas últimas 24 horas;
 *  - due(): se o e-mail deve sair agora. Sai quando as chaves mudam (problema novo, ou tudo voltou ao normal) e, enquanto a
 *    mesmas chaves continuarem (o texto muda com o tempo parado, a chave não), no máximo a cada REPEAT segundos. Sem problema e sem aviso anterior, não sai nada.
 *
 * O grupo "hora" é o que roda o vigia, então ele não avisa do próprio atraso: quem cobre o cron parado de vez é o monitor
 * externo olhando o /api/v1/health.
 */
final class HostingWatchdog
{
    /**
     * ALERT_EMAIL aceita mais de um endereço, separados por vírgula ou ponto e vírgula (Djalma, 23/11: SAC e o
     * e-mail dele). Endereço inválido fica de fora; lista vazia = não envia.
     *
     * @return list<string>
     */
    public static function recipients(string $value): array
    {
        $list = [];
        foreach (preg_split('/[,;\s]+/', $value) ?: [] as $address) {
            $address = strtolower(trim($address));
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && !in_array($address, $list, true)) {
                $list[] = $address;
            }
        }
        return $list;
    }

    public const REPEAT = 43200;

    private const LABELS = ['minuto' => 'a rotina de cada minuto (fila de avisos e e-mails)', 'hora' => 'a rotina de hora em hora (consulta dos boletos)',
        'dia' => 'a rotina diária (lembretes e alertas)', 'backup' => 'o backup diário'];

    /**
     * @param array{status:string, groups:array<string, array{status:string, age:?int}>} $evaluation
     * @return array<string, string>
     */
    public static function problems(array $evaluation, int $failedNotifications): array
    {
        $out = [];
        foreach ($evaluation['groups'] as $group => $g) {
            $label = self::LABELS[$group] ?? $group;
            if ($g['status'] === 'stale') {
                $out['stale:' . $group] = sprintf('%s não roda há %s.', ucfirst($label), self::duration((int)$g['age']));
            } elseif ($g['status'] === 'failing') {
                $out['failing:' . $group] = sprintf('%s rodou com falha. Veja storage/logs/cron-%s.log.', ucfirst($label), $group);
            }
        }
        if ($failedNotifications > 0) {
            $out['notifications'] = sprintf('%d aviso(s) da fila falharam nas últimas 24 horas. Veja Configurações › Fila de avisos.', $failedNotifications);
        }
        return $out;
    }

    /** Dias de antecedência para avisar do certificado do banco (o Inter e a Cora emitem por 1 ano; a troca leva dias). */
    public const BANK_CERT_WARN_DAYS = 30;

    /**
     * Certificados dos bancos (mTLS do Inter e da Cora, ADR 0352) vencidos ou perto de vencer. Sem o certificado válido a
     * emissão e a consulta dos boletos param. Chave estável por credencial e situação (vence/venceu), para o e-mail sair
     * de novo quando vencer de fato.
     *
     * @param list<array{id:int|string, adapter:string, environment:string, certificate_expires_on:?string, account:?string, condominium:?string}> $credentials
     * @return array<string, string>
     */
    public static function bankCertProblems(array $credentials, int $now): array
    {
        $out = [];
        $today = strtotime(date('Y-m-d', $now));
        foreach ($credentials as $c) {
            $expires = (string)($c['certificate_expires_on'] ?? '');
            if ($expires === '' || ($at = strtotime(substr($expires, 0, 10))) === false) {
                continue;
            }
            $days = (int)floor(($at - $today) / 86400);
            if ($days >= self::BANK_CERT_WARN_DAYS) {
                continue;
            }
            $bank = ['inter' => 'Banco Inter', 'cora' => 'Cora', 'sicoob' => 'Sicoob'][strtolower((string)$c['adapter'])] ?? (string)$c['adapter'];
            $where = trim(implode(' · ', array_filter([(string)($c['condominium'] ?? ''), (string)($c['account'] ?? '')])));
            $env = (string)$c['environment'] === 'production' ? 'produção' : 'teste';
            $label = sprintf('%s (%s%s)', $bank, $env, $where !== '' ? ', ' . $where : '');
            $out[($days < 0 ? 'bankcert-expired:' : 'bankcert:') . (int)$c['id']] = $days < 0
                ? sprintf('O certificado do %s venceu em %s. Sem ele os boletos não são emitidos nem consultados. Gere outro no banco e envie em Configurações › Integrações.', $label, date('d/m/Y', $at))
                : sprintf('O certificado do %s vence em %d dia(s) (%s). Gere outro no banco e envie em Configurações › Integrações antes disso.', $label, $days, date('d/m/Y', $at));
        }
        return $out;
    }

    /**
     * @param array<string, string> $problems
     * @param array{hash?:string, sent_at?:int} $state o último aviso enviado
     */
    public static function due(array $problems, array $state, int $now): bool
    {
        $hash = self::hash($problems);
        $last = (string)($state['hash'] ?? '');
        if ($hash !== $last) {
            return $hash !== '' || $last !== '';
        }
        return $hash !== '' && $now - (int)($state['sent_at'] ?? 0) >= self::REPEAT;
    }

    /** @param array<string, string> $problems */
    public static function hash(array $problems): string
    {
        $keys = array_keys($problems);
        sort($keys);
        return $keys === [] ? '' : hash('sha256', implode("\n", $keys));
    }

    /** @param array<string, string> $problems */
    public static function message(array $problems, string $host): array
    {
        if ($problems === []) {
            return ['subject' => '[Connect ERP] Tudo voltou ao normal em ' . $host, 'body' => '<p>As rotinas do servidor voltaram a rodar normalmente.</p>'];
        }
        $items = implode('', array_map(static fn (string $p): string => '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>', $problems));
        return ['subject' => '[Connect ERP] Atenção no servidor ' . $host . ' (' . count($problems) . ')',
            'body' => '<p>O vigia do servidor encontrou:</p><ul>' . $items . '</ul><p>O roteiro está em docs/process/HOSPEDAGEM.md, seção Monitoramento.</p>'];
    }

    private static function duration(int $seconds): string
    {
        if ($seconds >= 86400) {
            return intdiv($seconds, 86400) . ' dia(s)';
        }
        if ($seconds >= 3600) {
            return intdiv($seconds, 3600) . ' hora(s)';
        }
        return max(1, intdiv($seconds, 60)) . ' minuto(s)';
    }
}
