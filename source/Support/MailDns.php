<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Conferência do DNS de e-mail do domínio (ADR 0381): sem SPF, DKIM e DMARC os boletos e avisos caem no spam ou são
 * recusados (Gmail e Outlook exigem os três de quem manda muito e-mail). Só lê registros TXT públicos; não muda nada.
 *
 * O resolvedor é injetável para teste: recebe o nome e devolve a lista de textos TXT (vazia quando não há) ou null quando
 * a consulta ao DNS falhou; falha de consulta não é confundida com registro ausente.
 */
final class MailDns
{
    /**
     * @param callable(string): ?list<string> $txt
     * @return array{ok: bool, items: list<array{name: string, status: string, message: string}>}
     */
    public static function check(string $domain, ?string $dkimSelector, callable $txt): array
    {
        $domain = strtolower(trim($domain, " .\t\n\r"));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return ['ok' => false, 'items' => [['name' => 'domínio', 'status' => 'error', 'message' => 'Domínio inválido.']]];
        }
        $items = [self::lookup('SPF', $txt($domain), self::spf(...)), self::lookup('DMARC', $txt('_dmarc.' . $domain), self::dmarc(...))];
        $selector = trim((string)$dkimSelector);
        if ($selector === '') {
            $items[] = ['name' => 'DKIM', 'status' => 'warning', 'message' => 'Não conferido: informe o seletor em MAIL_DKIM_SELECTOR no .env ou --dkim-seletor (o hPanel mostra o seletor em E-mails > Autenticação).'];
        } elseif (!preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/i', $selector)) {
            $items[] = ['name' => 'DKIM', 'status' => 'error', 'message' => 'Seletor DKIM inválido.'];
        } else {
            $items[] = self::lookup('DKIM', $txt($selector . '._domainkey.' . $domain), static fn (array $r): array => self::dkim($selector, $r));
        }
        $ok = array_filter($items, static fn (array $item): bool => $item['status'] === 'error') === [];
        return ['ok' => $ok, 'items' => $items];
    }

    /** @param ?list<string> $records */
    private static function lookup(string $name, ?array $records, callable $rule): array
    {
        if ($records === null) {
            return ['name' => $name, 'status' => 'error', 'message' => 'Não foi possível consultar o DNS deste servidor; tente de novo ou confira por outro computador.'];
        }
        return $rule($records);
    }

    /** @param list<string> $records */
    private static function spf(array $records): array
    {
        $spf = array_values(array_filter($records, static fn (string $r): bool => preg_match('/^v=spf1(\s|$)/i', trim($r)) === 1));
        if ($spf === []) {
            return ['name' => 'SPF', 'status' => 'error', 'message' => 'Sem registro SPF (TXT "v=spf1 ..."): os provedores não sabem quem pode mandar pelo domínio.'];
        }
        if (count($spf) > 1) {
            return ['name' => 'SPF', 'status' => 'error', 'message' => 'Há ' . count($spf) . ' registros SPF; o padrão aceita só um (junte os include num registro).'];
        }
        $value = strtolower(trim($spf[0]));
        if (preg_match('/(^|\s)\+?all(\s|$)/', $value)) {
            return ['name' => 'SPF', 'status' => 'error', 'message' => 'SPF termina em "+all": qualquer servidor pode mandar em nome do domínio. Use "~all" ou "-all".'];
        }
        if (!preg_match('/(^|\s)[~-]all(\s|$)/', $value)) {
            return ['name' => 'SPF', 'status' => 'warning', 'message' => 'SPF sem "~all" ou "-all" no fim: e-mail falso em nome do domínio não é recusado.'];
        }
        return ['name' => 'SPF', 'status' => 'ok', 'message' => $spf[0]];
    }

    /** @param list<string> $records */
    private static function dmarc(array $records): array
    {
        $dmarc = array_values(array_filter($records, static fn (string $r): bool => preg_match('/^v=DMARC1\s*(;|$)/i', trim($r)) === 1));
        if ($dmarc === []) {
            return ['name' => 'DMARC', 'status' => 'error', 'message' => 'Sem registro DMARC em _dmarc (TXT "v=DMARC1; p=none; rua=mailto:..."); o Gmail exige.'];
        }
        if (count($dmarc) > 1) {
            return ['name' => 'DMARC', 'status' => 'error', 'message' => 'Há ' . count($dmarc) . ' registros DMARC; o padrão aceita só um.'];
        }
        if (!preg_match('/(^|;)\s*p\s*=\s*(none|quarantine|reject)\s*(;|$)/i', $dmarc[0], $policy)) {
            return ['name' => 'DMARC', 'status' => 'error', 'message' => 'DMARC sem política "p=" válida (none, quarantine ou reject).'];
        }
        if (strtolower($policy[2]) === 'none') {
            return ['name' => 'DMARC', 'status' => 'warning', 'message' => 'DMARC com p=none: bom para começar; depois de algumas semanas sem falha, passar para quarantine.'];
        }
        return ['name' => 'DMARC', 'status' => 'ok', 'message' => $dmarc[0]];
    }

    /** @param list<string> $records */
    private static function dkim(string $selector, array $records): array
    {
        foreach ($records as $record) {
            if (preg_match('/(^|;)\s*p\s*=\s*([A-Za-z0-9+\/=]*)\s*(;|$)/', $record, $key)) {
                if ($key[2] === '') {
                    return ['name' => 'DKIM', 'status' => 'error', 'message' => "Chave DKIM do seletor {$selector} revogada (p= vazio)."];
                }
                return ['name' => 'DKIM', 'status' => 'ok', 'message' => "Chave DKIM do seletor {$selector} publicada."];
            }
        }
        return ['name' => 'DKIM', 'status' => 'error', 'message' => "Sem chave DKIM em {$selector}._domainkey: ative o DKIM no hPanel e confira o seletor."];
    }

    /**
     * Avisos do deploy-check (ADR 0382): com o envio ligado (ERP_MAIL_DELIVERY=production), confere o domínio do remetente.
     * Sempre aviso, nunca erro: o DNS pode demorar a propagar e não deve impedir a publicação nem disparar a volta de versão.
     *
     * @param array<string, string> $env
     * @param callable(string): ?list<string> $txt
     * @return list<string>
     */
    public static function deployWarnings(array $env, callable $txt): array
    {
        if (($env['ERP_MAIL_DELIVERY'] ?? '') !== 'production') {
            return [];
        }
        $from = trim((string)($env['MOVESOS_SMTP_FROM_ADDRESS'] ?? ''));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return ['MOVESOS_SMTP_FROM_ADDRESS vazio ou inválido: o DNS de e-mail (SPF, DKIM, DMARC) não foi conferido.'];
        }
        $selector = trim((string)($env['MAIL_DKIM_SELECTOR'] ?? ''));
        $result = self::check(substr($from, strrpos($from, '@') + 1), $selector === '' ? null : $selector, $txt);
        $warnings = [];
        foreach ($result['items'] as $item) {
            if ($item['status'] !== 'ok') {
                $warnings[] = "E-mail ({$item['name']}): {$item['message']} Detalhes: php tools/hosting/email-teste.php --so-dns.";
            }
        }
        return $warnings;
    }

    /** Resolvedor real: TXT público pelo DNS do servidor. Registros longos vêm em pedaços e são juntados. */
    public static function resolver(): callable
    {
        return static function (string $name): array {
            $records = @dns_get_record($name, DNS_TXT);
            if (!is_array($records)) {
                return null;
            }
            $texts = [];
            foreach ($records as $record) {
                $texts[] = isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : (string)($record['txt'] ?? '');
            }
            return $texts;
        };
    }
}
