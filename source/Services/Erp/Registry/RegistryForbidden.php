<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/** O usuário vê o registro mas não pode alterá-lo (ex.: contato compartilhado sem acesso global). */
final class RegistryForbidden extends RuntimeException
{
}
