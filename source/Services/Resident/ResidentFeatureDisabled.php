<?php

declare(strict_types=1);

namespace Source\Services\Resident;

/** A função não está ligada para este vínculo (matriz do portal ou tipo de vínculo, ADR 0302). */
final class ResidentFeatureDisabled extends \RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct('Esta função ainda não foi liberada pelo seu condomínio.');
    }
}
