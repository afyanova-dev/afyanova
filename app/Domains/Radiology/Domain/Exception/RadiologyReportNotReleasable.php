<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReleaseRadiologyReport` was attempted on a report that fails the release
 * gate (15-radiology-domain.md §52, §161, §211; ADR-167 D-C — the Lab.3a
 * `LabResultNotReleasable` precedent). Maps to HTTP 409.
 */
final class RadiologyReportNotReleasable extends DomainException
{
    public static function notVerified(string $reportId, string $status): self
    {
        return new self(sprintf(
            'Radiology report [%s] is [%s] — only a VERIFIED report can be released.',
            $reportId,
            $status,
        ));
    }

    public static function onHold(string $reportId, string $reason): self
    {
        return new self(sprintf(
            'Radiology report [%s] is on release hold [%s] and cannot be released.',
            $reportId,
            $reason,
        ));
    }

    public function errorCode(): string
    {
        return 'radiology_report_not_releasable';
    }
}
