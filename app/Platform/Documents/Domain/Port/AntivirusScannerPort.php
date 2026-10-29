<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Port;

interface AntivirusScannerPort
{
    public function scan(string $contents, string $mimeType): ScanResultDto;
}
