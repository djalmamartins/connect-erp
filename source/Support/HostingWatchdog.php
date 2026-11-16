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
