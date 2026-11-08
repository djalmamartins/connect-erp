<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

/**
 * Cálculos de posição do check-in. A posição vem do aparelho (Geolocation API) e pode ser
 * falsificada: o servidor só confere a distância e registra o que recebeu, como evidência.
 */
final class Geo
{
    public const EARTH_RADIUS_M = 6371008.8;
    public const MIN_RADIUS_M = 30;
    public const MAX_RADIUS_M = 2000;

    /** Distância em metros entre dois pontos (fórmula de haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    public static function latitude(mixed $value): ?float
    {
        return self::coordinate($value, 90.0);
    }

    public static function longitude(mixed $value): ?float
    {
        return self::coordinate($value, 180.0);
    }

    /** Precisão informada pelo aparelho, em metros (descarta valores absurdos). */
    public static function accuracy(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite($number) || $number < 0 || $number > 100000) {
            return null;
        }
        return round($number, 1);
    }

    /**
     * Situação do check-in.
     * inside: dentro do raio; outside: fora; no_reference: condomínio sem ponto cadastrado;
     * no_signal: o aparelho não informou posição. A precisão do GPS amplia o raio aceito até
     * o dobro, para não reprovar quem está no condomínio com sinal ruim.
     *
     * @param array{latitude: float, longitude: float, radius_m: int}|null $reference
     * @return array{status: string, distance: ?float}
     */
    public static function checkin(?float $lat, ?float $lng, ?float $accuracy, ?array $reference): array
    {
        if ($lat === null || $lng === null) {
            return ['status' => 'no_signal', 'distance' => null];
        }
        if ($reference === null) {
            return ['status' => 'no_reference', 'distance' => null];
        }
        $distance = round(self::distance($lat, $lng, $reference['latitude'], $reference['longitude']), 1);
        $radius = (float)$reference['radius_m'];
        $tolerance = min($radius, max(0.0, (float)($accuracy ?? 0.0)));
        return ['status' => $distance <= $radius + $tolerance ? 'inside' : 'outside', 'distance' => $distance];
    }

    private static function coordinate(mixed $value, float $limit): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var(str_replace(',', '.', (string)$value), FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite($number) || abs($number) > $limit) {
            return null;
        }
        return round($number, 7);
    }
}
