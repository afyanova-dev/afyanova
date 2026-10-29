<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `VerifyRadiologyReport` was attempted on a report with no `findings_text` or
 * no `impression_text` (15-radiology-domain.md §40, §161; ADR-167 D-C). A
 * report cannot be verified empty. Maps to HTTP 409.
 */
final class RadiologyReportIncomplete extends DomainException
{
    public static function forVerify(string $reportId): self
    {
        return new self(sprintf(
            'Radiology report [%s] has no findings or no impression — it cannot be verified.',
            $reportId,
        ));
    }

    public function errorCode(): string
    {
        return 'radiology_report_incomplete';
    }
}
