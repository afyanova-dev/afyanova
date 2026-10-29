<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ScheduleImagingStudy` was attempted for an order item that already has a
 * non-terminal study (SCHEDULED / READY / IN_PROGRESS) (15-radiology-domain.md
 * §106; ADR-166 D-F). An order line may accumulate multiple studies over time
 * (a re-schedule after a NO_SHOW, a repeat §60) but only one may be in flight.
 * Maps to HTTP 409.
 */
final class ImagingStudyAlreadyActive extends DomainException
{
    public static function forOrderItem(string $orderItemId, string $activeStudyId): self
    {
        return new self(sprintf(
            'Order item [%s] already has an active imaging study [%s] — complete, cancel or abort it first.',
            $orderItemId,
            $activeStudyId,
        ));
    }

    public function errorCode(): string
    {
        return 'imaging_study_already_active';
    }
}
