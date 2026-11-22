<?php

declare(strict_types=1);

namespace Source\Support\Erp;

/**
 * Base única de relatório para imprimir ou salvar em PDF (ADR 0635).
 *
 * Todo botão "Imprimir" do ERP (data-erp-print) abre a mesma tela com ?imprimir=1. Nesse modo a moldura
 * (default.php) troca o menu e a barra superior por uma folha A4 com a faixa verde-escura e dourada do
 * Livro caixa (ADR 0174), e o cabeçalho de página (page-head.php) vira o cabeçalho do relatório: logo,
 * grupo, título, condomínio da barra superior e quem emitiu. Os filtros viram a linha de parâmetros.
 * Os dados são os mesmos da tela, com as mesmas permissões: nada é consultado de novo de outro jeito.
 */
final class ReportSheet
{
    public const PARAM = 'imprimir';

    /** @param array<string,mixed>|null $query padrão: $_GET */
    public static function active(?array $query = null): bool
    {
        $query ??= $_GET;
        return isset($query[self::PARAM]) && $query[self::PARAM] === '1';
    }

    /**
     * Endereço da mesma tela no modo relatório, mantendo os filtros.
     *
     * @param array<string,mixed> $query
     */
    public static function url(string $path, array $query = []): string
    {
        $query[self::PARAM] = '1';
        return $path . '?' . http_build_query($query);
    }

    /** "22/11/2026 às 08:30" no fuso do sistema. */
    public static function issuedAt(?int $timestamp = null): string
    {
        return date('d/m/Y \à\s H:i', $timestamp ?? time());
    }

    /** Nome de quem emitiu, a partir do usuário da sessão (objeto ou array). */
    public static function issuer(mixed $user): string
    {
        $first = is_object($user) ? ($user->first_name ?? '') : (is_array($user) ? ($user['first_name'] ?? '') : '');
        $last = is_object($user) ? ($user->last_name ?? '') : (is_array($user) ? ($user['last_name'] ?? '') : '');
        return trim((string)$first . ' ' . (string)$last);
    }
}
