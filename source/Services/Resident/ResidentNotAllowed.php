<?php

declare(strict_types=1);

namespace Source\Services\Resident;

/** O vínculo do morador não permite esta ação (ex.: só o proprietário bloqueia inquilino). API: 403 not_allowed. */
final class ResidentNotAllowed extends \RuntimeException
{
}
