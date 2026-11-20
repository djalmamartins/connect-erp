<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Prazo do PHP para os PDFs grandes (ADR 0385). Medido em 18/11/2026: lista de 5.000 linhas ~23 s (ADR 0383),
 * livro-caixa de 1.000 lançamentos ~23 s (ADR 0384), prestação de contas de 1.000 lançamentos ~14 s. Na hospedagem o
 * max_execution_time costuma ser 30 s e o CLI não mostra o valor do site, então o deploy-check não confere: cada PDF
 * grande pede o prazo antes de montar.
 */
final class PdfTime
{
    public const SECONDS = 120;

    /** Regra pura: só aumenta (0 é "sem limite"; um prazo maior já dado por quem chamou fica). */
    public static function needed(int $current, int $want = self::SECONDS): bool
    {
        return $current !== 0 && $current < $want;
    }

    public static function extend(int $want = self::SECONDS): void
    {
        if (self::needed((int)ini_get('max_execution_time'), $want)) {
            @set_time_limit($want);
        }
    }
}
