<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Service;

use App\Domains\Radiology\Domain\Exception\RadiologyReportNotReleasable;
use App\Domains\Radiology\Domain\Model\RadiologyReport;

/**
 * The report-release safety gate (15-radiology-domain.md §52, §161, §211;
 * ADR-167 D-C — the Lab.3a `LabResultReleaseGate` verbatim). Pure domain logic.
 *
 * A released report must satisfy every pre-condition (§52 / §161: verified /
 * correct patient / correct study / not blocked). Patient / study consistency
 * is enforced at report CREATE (`CreateRadiologyReportHandler` snapshots from
 * the loaded study), so a VERIFIED report is already correctly bound; this gate
 * checks status VERIFIED and a clear `release_hold_reason` (the generic block
 * hook, reserved for a future peer-review / addendum-pending increment).
 */
final class RadiologyReportReleaseGate
{
    public function assertReleasable(RadiologyReport $report): void
    {
        if ((string) $report->status !== RadiologyReport::STATUS_VERIFIED) {
            throw RadiologyReportNotReleasable::notVerified((string) $report->id, (string) $report->status);
        }

        if ($report->release_hold_reason !== null && trim((string) $report->release_hold_reason) !== '') {
            throw RadiologyReportNotReleasable::onHold((string) $report->id, (string) $report->release_hold_reason);
        }
    }
}
