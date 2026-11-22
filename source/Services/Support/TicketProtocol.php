<?php

declare(strict_types=1);

namespace Source\Services\Support;

use DateTimeImmutable;
use PDO;

/**
 * Protocolo dos chamados no mesmo formato do Connect Talk (ADR 0636).
 *
 * - Chamado (SAC, Help Desk, site, API, Talk): SAC + AAAAMMDD + sequência do dia com 4 dígitos, ex. SAC202611160008.
 * - Ocorrência (que também é chamado, ADR 0305): OCO + AAAAMMDD + sequência do dia, ex. OCO202611160003.
 * - Cada prefixo tem a própria sequência, que recomeça em 0001 todo dia. Passou de 9999 no dia, segue com 5 dígitos.
 * - Os protocolos antigos (CH + aammdd + 4 hex e OC + 10 dígitos) não mudam e continuam sendo encontrados na busca.
 */
final class TicketProtocol
{
    public const KIND_TICKET = 'ticket';
    public const KIND_OCCURRENCE = 'occurrence';
    public const PREFIXES = [self::KIND_TICKET => 'SAC', self::KIND_OCCURRENCE => 'OCO'];

    /** Formatos aceitos: os novos e os dois antigos. */
    private const PATTERN = '/^(?:(?:SAC|OCO)\d{12,13}|CH\d{6}[A-F0-9]{4}|OC\d{10})$/';

    /** Monta o protocolo (regra pura). */
    public static function format(string $kind, DateTimeImmutable $day, int $sequence): string
    {
        $prefix = self::PREFIXES[$kind] ?? self::PREFIXES[self::KIND_TICKET];
        return $prefix . $day->format('Ymd') . str_pad((string)max(1, $sequence), 4, '0', STR_PAD_LEFT);
    }

    /**
     * O texto digitado como protocolo, sem espaços, pontos, barras ou traços e em maiúsculas ("sac 2026-1116-0008"
     * vira SAC202611160008). Devolve null quando não parece protocolo (regra pura).
     */
    public static function normalize(string $typed): ?string
    {
        $compact = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $typed));
        return preg_match(self::PATTERN, $compact) === 1 ? $compact : null;
    }

    public static function isValid(string $protocol): bool
    {
        return preg_match(self::PATTERN, $protocol) === 1;
    }

    /** Tipo pelo prefixo: OCO e o antigo OC são ocorrência; o resto é chamado (regra pura). */
    public static function kindOf(string $protocol): string
    {
        return str_starts_with($protocol, 'OC') ? self::KIND_OCCURRENCE : self::KIND_TICKET;
    }

    /**
     * Próximo protocolo do dia. Precisa rodar dentro da transação que grava o chamado: a linha da sequência
     * fica travada até o commit, e se o chamado não for gravado o número volta (rollback). Número já usado
     * (ex.: um protocolo que veio do Talk) é pulado.
     */
    public static function next(PDO $pdo, string $kind, ?DateTimeImmutable $now = null): string
    {
        $prefix = self::PREFIXES[$kind] ?? self::PREFIXES[self::KIND_TICKET];
        $day = $now ?? new DateTimeImmutable('now');
        $bump = $pdo->prepare('INSERT INTO studio_support_protocol_sequences (prefix, day, last_number) VALUES (:p, :d, 1)
            ON DUPLICATE KEY UPDATE last_number = last_number + 1');
        $read = $pdo->prepare('SELECT last_number FROM studio_support_protocol_sequences WHERE prefix = :p AND day = :d FOR UPDATE');
        $taken = $pdo->prepare('SELECT 1 FROM studio_support_tickets WHERE protocol = ?');
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $bump->execute(['p' => $prefix, 'd' => $day->format('Y-m-d')]);
            $read->execute(['p' => $prefix, 'd' => $day->format('Y-m-d')]);
            $protocol = self::format($kind, $day, (int)$read->fetchColumn());
            $taken->execute([$protocol]);
            if (!$taken->fetchColumn()) {
                return $protocol;
            }
        }
        throw new \RuntimeException('Não foi possível gerar o protocolo do chamado.');
    }

    /** O protocolo que veio de fora (Talk) pode ser o do chamado? Formato SAC do dia certo e ainda livre. */
    public static function canAdopt(PDO $pdo, string $protocol): bool
    {
        if (preg_match('/^SAC\d{12,13}$/', $protocol) !== 1) {
            return false;
        }
        $day = DateTimeImmutable::createFromFormat('!Ymd', substr($protocol, 3, 8));
        if ($day === false || $day->format('Ymd') !== substr($protocol, 3, 8)) {
            return false;
        }
        $taken = $pdo->prepare('SELECT 1 FROM studio_support_tickets WHERE protocol = ?');
        $taken->execute([$protocol]);
        return !$taken->fetchColumn();
    }
}
