<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

/** Status gravado em erp_documents.scan_status (ADR 0098). */
final class ScanStatus
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const UNSCANNED = 'unscanned';
    public const ERROR = 'error';
    public const ALL = [self::CLEAN, self::INFECTED, self::UNSCANNED, self::ERROR];
}
