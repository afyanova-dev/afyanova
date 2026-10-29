<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateRadiologyReport` was attempted for a study that already has a report
 * (15-radiology-domain.md §184, §188; ADR-167 D-B — one report per study,
 * `unique(tenant_id, study_id)`). Maps to HTTP 409.
 */
final class RadiologyReportAlreadyExists extends DomainException
{
    public static function forStudy(string $studyId): self
    {
        return new self(sprintf(
            'Imaging study [%s] already has a radiology report — use amendment / addendum to correct it.',
            $studyId,
        ));
    }

    public function errorCode(): string
    {
        return 'radiology_report_already_exists';
    }
}
