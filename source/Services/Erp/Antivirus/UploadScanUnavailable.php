<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

/** Modo enforce sem antivírus que responda (ADR 0098): o upload é recusado (503), falha fechada. */
final class UploadScanUnavailable extends \RuntimeException
{
}
