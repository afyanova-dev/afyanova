<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ScheduleImagingStudy` named an order that is not in a state where a study
 * can be scheduled (15-radiology-domain.md §19–§20, §105; ADR-166 D-F). The
 * order must be ACCEPTED / SCHEDULED / IN_PROGRESS (the Lab.2
 * `LabOrderNotReadyForSpecimen` precedent). Maps to HTTP 409.
 */
final class ImagingOrderNotReadyForStudy extends DomainException
{
    public static function status(string $orderId, string $status): self
    {
        return new self(sprintf(
            'Imaging order [%s] is [%s] — a study can only be scheduled for an ACCEPTED, SCHEDULED or IN_PROGRESS order.',
            $orderId,
            $status,
        ));
    }

    public static function unknownItem(string $orderId, string $orderItemId): self
    {
        return new self(sprintf(
            'Order item [%s] does not belong to imaging order [%s], or is not ORDERED.',
            $orderItemId,
            $orderId,
        ));
    }

    public function errorCode(): string
    {
        return 'imaging_order_not_ready_for_study';
    }
}
