<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/** Registro inexistente, excluído ou fora do escopo do usuário (tratado sempre como 404). */
final class RegistryNotFound extends RuntimeException
{
}
