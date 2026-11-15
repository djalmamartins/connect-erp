<?php

declare(strict_types=1);

namespace Source\Support\Erp;

/**
 * Itens por página das listas do ERP (revisão de 15/11, ADR 0310): 50 por padrão, com 100, 200 e 500 à escolha
 * (?por_pagina= nas telas, per_page na API). Qualquer outro valor volta ao padrão, para ninguém pedir 100 mil linhas.
 */
final class PerPage
{
    public const OPTIONS = [50, 100, 200, 500];
    public const DEFAULT = 50;

    public static function from(mixed $value): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($n) && in_array($n, self::OPTIONS, true) ? $n : self::DEFAULT;
    }

    /** O valor da tela atual (?por_pagina=), já validado. */
    public static function current(): int
    {
        return self::from($_GET['por_pagina'] ?? null);
    }
}
