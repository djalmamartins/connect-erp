<?php
declare(strict_types=1);

namespace Source\Services\Talk\Support;

/** Telefones brasileiros: normalização e variações para busca no ERP. */
final class Phone
{
    /** "(31) 99999-8888" / "5531999998888@s.whatsapp.net" → "5531999998888" */
    public static function normalize(string $raw): string
    {
        $raw = explode('@', $raw)[0];
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 11) {           // sem DDI
            $digits = '55' . ltrim($digits, '0');
        }
        return $digits;
    }

    /**
     * Variações para achar a pessoa no cadastro: com/sem DDI e com/sem o 9º dígito
     * (o WhatsApp às vezes entrega celulares antigos sem o 9).
     * @return list<string>
     */
    public static function candidates(string $raw): array
    {
        $n = self::normalize($raw);
        if (!str_starts_with($n, '55') || strlen($n) < 12) {
            return [$n];
        }
        $local = substr($n, 2);                // DDD + número
        $ddd = substr($local, 0, 2);
        $num = substr($local, 2);
        $set = [$local];
        if (strlen($num) === 9 && $num[0] === '9') {
            $set[] = $ddd . substr($num, 1);
        } elseif (strlen($num) === 8) {
            $set[] = $ddd . '9' . $num;
        }
        $out = [];
        foreach ($set as $l) {
            $out[] = '55' . $l;
            $out[] = $l;
        }
        return array_values(array_unique($out));
    }
}
