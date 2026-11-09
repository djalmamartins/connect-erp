<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Lançada por redirect() quando MOVESOS_ENV=testing. Em produção redirect() termina o pedido com
 * exit; numa suíte de testes o mesmo exit encerrava o PHPUnit no meio, sem falha nem relatório.
 */
final class RedirectIntercepted extends \RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct("redirect() para {$location}");
    }
}
