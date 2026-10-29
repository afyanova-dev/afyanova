<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CollectSpecimen` named an order that is not in a state where a specimen can
 * be collected (14-laboratory-domain.md §21–§24; ADR-158 D-F). A specimen is
 * collected against an ACCEPTED / SPECIMEN_PENDING / PROCESSING order — never a
 * DRAFT, ORDERED, REJECTED or CANCELLED one. Maps to HTTP 409.
 */
final class LabOrderNotReadyForSpecimen extends DomainException
{
    public static function status(string $orderId, string $status): self
    {
        return new self(sprintf(
            'Laboratory order [%s] is [%s] — specimen collection requires ACCEPTED, SPECIMEN_PENDING or PROCESSING.',
            $orderId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'lab_order_not_ready_for_specimen';
    }
}
