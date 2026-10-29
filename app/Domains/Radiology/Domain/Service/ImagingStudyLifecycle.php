<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Service;

use App\Domains\Radiology\Domain\Exception\IllegalImagingStudyTransition;
use App\Domains\Radiology\Domain\Model\ImagingStudy;

/**
 * Imaging-study lifecycle rules (15-radiology-domain.md §104, §161; ADR-166 D-D
 * — the `LabSpecimenLifecycle` precedent). Pure domain logic, no I/O.
 *
 * SCHEDULED -> READY                (optional)
 * SCHEDULED | READY -> IN_PROGRESS  (StartImagingStudy — requires patientVerified)
 * IN_PROGRESS -> COMPLETED
 * SCHEDULED | READY -> CANCELLED
 * SCHEDULED | READY -> NO_SHOW
 * IN_PROGRESS -> ABORTED
 */
final class ImagingStudyLifecycle
{
    public function assertCanMarkReady(string $status): void
    {
        if ($status !== ImagingStudy::STATUS_SCHEDULED) {
            throw IllegalImagingStudyTransition::fromStatus('MarkStudyReady', $status);
        }
    }

    public function assertCanStart(string $status): void
    {
        if (! in_array($status, [ImagingStudy::STATUS_SCHEDULED, ImagingStudy::STATUS_READY], true)) {
            throw IllegalImagingStudyTransition::fromStatus('StartImagingStudy', $status);
        }
    }

    public function assertCanComplete(string $status): void
    {
        if ($status !== ImagingStudy::STATUS_IN_PROGRESS) {
            throw IllegalImagingStudyTransition::fromStatus('CompleteImagingStudy', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [ImagingStudy::STATUS_SCHEDULED, ImagingStudy::STATUS_READY], true)) {
            throw IllegalImagingStudyTransition::fromStatus('CancelImagingStudy', $status);
        }
    }

    public function assertCanRecordNoShow(string $status): void
    {
        if (! in_array($status, [ImagingStudy::STATUS_SCHEDULED, ImagingStudy::STATUS_READY], true)) {
            throw IllegalImagingStudyTransition::fromStatus('RecordImagingNoShow', $status);
        }
    }

    public function assertCanAbort(string $status): void
    {
        if ($status !== ImagingStudy::STATUS_IN_PROGRESS) {
            throw IllegalImagingStudyTransition::fromStatus('AbortImagingStudy', $status);
        }
    }
}
