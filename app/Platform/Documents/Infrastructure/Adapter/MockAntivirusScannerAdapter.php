<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

use App\Platform\Documents\Domain\Port\AntivirusScannerPort;
use App\Platform\Documents\Domain\Port\ScanResultDto;

final class MockAntivirusScannerAdapter implements AntivirusScannerPort
{
    public function scan(string $contents, string $mimeType): ScanResultDto
    {
        // Check for EICAR standard test signature or explicit malware tag
        if (str_contains($contents, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') || str_contains($contents, 'MALWARE_DETECTED')) {
            return new ScanResultDto(
                isClean: false,
                threatName: 'Eicar-Test-Signature',
            );
        }

        return new ScanResultDto(isClean: true);
    }
}
