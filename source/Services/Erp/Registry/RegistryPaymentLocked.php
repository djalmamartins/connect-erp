<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/**
 * Exclusão recusada (ADR 0194): a conta está conciliada com o extrato ou tem boleto pago pelo banco (PaymentLocks). A API
 * responde 409 payment_locked; a tela volta ao registro com a mensagem.
 */
final class RegistryPaymentLocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
