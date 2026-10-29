<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordSummary;

/**
 * Read-side port for AnaesthesiaRecord queries (ADR-155 D-F).
 */
interface AnaesthesiaRecordReader
{
    public function summary(string $anaesthesiaRecordId): AnaesthesiaRecordSummary;

    public function forCase(string $caseId): ?AnaesthesiaRecordSummary;
}
