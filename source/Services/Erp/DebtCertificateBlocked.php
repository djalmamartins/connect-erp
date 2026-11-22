<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;

/**
 * Certidão de débitos recusada porque a unidade tem conta vencida em aberto (ADR 0638). Nenhum caminho do sistema
 * (ERP, API, app do morador no web e no celular) gera certidão com débito: só informa o débito.
 */
final class DebtCertificateBlocked extends DomainException
{
    public function __construct(public readonly int $overdueCount, public readonly int $overdueCents)
    {
        parent::__construct(self::text($overdueCount, $overdueCents));
    }

    /** Mensagem mostrada a quem pediu (regra pura). */
    public static function text(int $count, int $cents): string
    {
        return sprintf('A unidade tem %d conta(s) vencida(s) em aberto (R$ %s). A certidão negativa de débitos não é emitida enquanto houver débito.',
            $count, number_format($cents / 100, 2, ',', '.'));
    }
}
