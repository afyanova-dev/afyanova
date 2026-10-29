<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Service;

use App\Domains\Radiology\Domain\Exception\IllegalRadiologyReportTransition;
use App\Domains\Radiology\Domain\Model\RadiologyReport;

/**
 * Radiology-report lifecycle rules (15-radiology-domain.md §42, §45, §103,
 * §161, §164; ADR-167 D-C, ADR-168 D-C — the `LabResultLifecycle` precedent).
 * Pure domain logic, no I/O.
 *
 * Rad.3a: DRAFT → REVIEWED (optional) → VERIFIED → RELEASED. Update is allowed
 * only while DRAFT | REVIEWED. VerifyRadiologyReport additionally requires a
 * non-blank findings + impression (§161 — a report cannot be verified empty).
 * Rad.3b: a CORRECTION / ADDENDUM (`AmendResult`) applies to a finalized report
 * ({VERIFIED, RELEASED, AMENDED} → AMENDED); a CANCELLATION invalidates a
 * non-released report ({DRAFT, REVIEWED, VERIFIED} → CANCELLED) — a RELEASED
 * report is corrected via amendment, never cancelled (§164).
 */
final class RadiologyReportLifecycle
{
    public function assertCanUpdate(string $status): void
    {
        if (! in_array($status, [RadiologyReport::STATUS_DRAFT, RadiologyReport::STATUS_REVIEWED], true)) {
            throw IllegalRadiologyReportTransition::fromStatus('UpdateRadiologyReport', $status);
        }
    }

    public function assertCanMarkReviewed(string $status): void
    {
        if ($status !== RadiologyReport::STATUS_DRAFT) {
            throw IllegalRadiologyReportTransition::fromStatus('MarkReportReviewed', $status);
        }
    }

    public function assertCanVerify(string $status): void
    {
        if (! in_array($status, [RadiologyReport::STATUS_DRAFT, RadiologyReport::STATUS_REVIEWED], true)) {
            throw IllegalRadiologyReportTransition::fromStatus('VerifyRadiologyReport', $status);
        }
    }

    public function assertCanRelease(string $status): void
    {
        if ($status !== RadiologyReport::STATUS_VERIFIED) {
            throw IllegalRadiologyReportTransition::fromStatus('ReleaseRadiologyReport', $status);
        }
    }

    public function assertCanAmend(string $status): void
    {
        if (! in_array($status, [
            RadiologyReport::STATUS_VERIFIED,
            RadiologyReport::STATUS_RELEASED,
            RadiologyReport::STATUS_AMENDED,
        ], true)) {
            throw IllegalRadiologyReportTransition::fromStatus('AmendRadiologyReport', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [
            RadiologyReport::STATUS_DRAFT,
            RadiologyReport::STATUS_REVIEWED,
            RadiologyReport::STATUS_VERIFIED,
        ], true)) {
            throw IllegalRadiologyReportTransition::fromStatus('CancelRadiologyReport', $status);
        }
    }

    /** A report can be flagged with a critical finding only once it is finalized (§160 analogue). */
    public function assertCanFlagCriticalFinding(string $status): void
    {
        if (! in_array($status, [
            RadiologyReport::STATUS_VERIFIED,
            RadiologyReport::STATUS_RELEASED,
            RadiologyReport::STATUS_AMENDED,
        ], true)) {
            throw IllegalRadiologyReportTransition::fromStatus('RecordCriticalFinding', $status);
        }
    }
}
