<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

use DateTimeImmutable;

/** Periodicidade dos planos de vistoria e situação de cada plano (em dia, vence em breve, atrasada). */
final class Schedule
{
    public const FREQUENCIES = [
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
        'quarterly' => 'Trimestral',
        'semiannual' => 'Semestral',
        'yearly' => 'Anual',
    ];

    private const INTERVALS = [
        'weekly' => 'P7D',
        'biweekly' => 'P14D',
        'monthly' => 'P1M',
        'quarterly' => 'P3M',
        'semiannual' => 'P6M',
        'yearly' => 'P1Y',
    ];

    /** Dias antes do vencimento em que o plano passa a aparecer como "vence em breve". */
    private const SOON_DAYS = [
        'weekly' => 1,
        'biweekly' => 2,
        'monthly' => 5,
        'quarterly' => 10,
        'semiannual' => 15,
        'yearly' => 30,
    ];

    public static function valid(string $frequency): bool
    {
        return isset(self::INTERVALS[$frequency]);
    }

    public static function label(string $frequency): string
    {
        return self::FREQUENCIES[$frequency] ?? $frequency;
    }

    /**
     * Próxima data devida: início do plano enquanto não houver vistoria concluída; depois,
     * a data da última conclusão somada à periodicidade.
     */
    public static function nextDue(string $frequency, DateTimeImmutable $startsOn, ?DateTimeImmutable $lastCompleted): DateTimeImmutable
    {
        if (!self::valid($frequency)) {
            throw new \InvalidArgumentException('Periodicidade inválida.');
        }
        if ($lastCompleted === null) {
            return $startsOn->setTime(0, 0);
        }
        return $lastCompleted->setTime(0, 0)->add(new \DateInterval(self::INTERVALS[$frequency]));
    }

    /** @return 'overdue'|'due_soon'|'ok' */
    public static function status(string $frequency, DateTimeImmutable $nextDue, DateTimeImmutable $today): string
    {
        $today = $today->setTime(0, 0);
        $nextDue = $nextDue->setTime(0, 0);
        if ($nextDue < $today) {
            return 'overdue';
        }
        $days = (int)$today->diff($nextDue)->format('%a');
        return $days <= (self::SOON_DAYS[$frequency] ?? 0) ? 'due_soon' : 'ok';
    }
}
