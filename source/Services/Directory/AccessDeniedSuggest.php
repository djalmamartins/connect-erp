<?php

declare(strict_types=1);

namespace Source\Services\Directory;

use RuntimeException;

/** Quem busca não pode ver nenhuma das fontes pedidas (ADR 0687). */
final class AccessDeniedSuggest extends RuntimeException
{
}
