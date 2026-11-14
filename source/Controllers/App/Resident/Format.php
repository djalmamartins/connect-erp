<?php

declare(strict_types=1);

namespace Source\Controllers\App\Resident;

/** Formatos e rótulos das telas do aplicativo do morador (ADR 0302), regras puras. */
final class Format
{
    public const SITUATIONS = ['open' => ['Em aberto', 'warning'], 'overdue' => ['Vencido', 'danger'], 'paid' => ['Pago', 'success'],
        'renegotiated' => ['Renegociado', 'neutral']];
    public const RESERVATION = ['active' => ['Aguardando aprovação', 'warning'], 'confirmed' => ['Confirmada', 'success'], 'inactive' => ['Cancelada', 'neutral']];
    public const OCCURRENCE = ['active' => ['Aberta', 'warning'], 'running' => ['Em andamento', 'info'], 'done' => ['Resolvida', 'success']];
    public const DELIVERY = ['active' => ['Aguardando retirada', 'warning'], 'picked_up' => ['Retirada', 'success']];
    public const VISIT = ['active' => ['Prevista', 'info'], 'done' => ['Realizada', 'success'], 'inactive' => ['Cancelada', 'neutral']];
    public const ASSEMBLY = ['convened' => ['Convocada', 'info'], 'open' => ['Em andamento', 'warning'], 'closed' => ['Encerrada', 'neutral']];
    public const CARRIERS = ['correios' => 'Correios', 'mercado_livre' => 'Mercado Livre', 'amazon' => 'Amazon', 'outra' => 'Outra'];
    public const PRIORITIES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];
    public const FOLDERS_ORDER = ['atas', 'contratos', 'plantas', 'notas_fiscais', 'outros'];
    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function money(?int $cents): string
    {
        return 'R$ ' . number_format(($cents ?? 0) / 100, 2, ',', '.');
    }

    public static function date(?string $date): string
    {
        if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return '—';
        }
        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }

    public static function dateTime(?string $value): string
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $value, $m)) {
            return self::date($value);
        }
        return "{$m[3]}/{$m[2]}/{$m[1]} às {$m[4]}:{$m[5]}";
    }

    /** "2026-10" → "outubro de 2026"; "2026" → "ano de 2026". */
    public static function competence(string $competence): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $competence, $m)) {
            return (self::MONTHS[(int)$m[2] - 1] ?? $m[2]) . ' de ' . $m[1];
        }
        return preg_match('/^\d{4}$/', $competence) ? 'ano de ' . $competence : $competence;
    }

    /** @param array<string, array{0:string,1:string}> $map @return array{0:string,1:string} rótulo e tom */
    public static function badge(array $map, string $status): array
    {
        return $map[$status] ?? [$status, 'neutral'];
    }
}
