<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AmendRadiologyReport` or `CancelRadiologyReport` was dispatched without a
 * non-blank `reason` (15-radiology-domain.md §44, §96, §158; ADR-168 D-D — the
 * Lab.3b `ResultAmendmentIncomplete` precedent). A correction to a
 * high-integrity record must record why. Maps to HTTP 409.
 */
final class ReportAmendmentIncomplete extends DomainException
{
    public static function missingReason(): self
    {
        return new self('A radiology report amendment / cancellation requires a non-blank reason.');
    }

    public function errorCode(): string
    {
        return 'report_amendment_incomplete';
    }
}
