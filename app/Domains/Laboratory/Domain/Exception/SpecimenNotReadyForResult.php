<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordResult` named a specimen or order item that is not in a state where a
 * result can be entered (14-laboratory-domain.md §41, §156; ADR-159 D-F).
 * A result is entered against an ACCEPTED / PROCESSING specimen whose order
 * item belongs to the same order. Maps to HTTP 409.
 */
final class SpecimenNotReadyForResult extends DomainException
{
    public static function specimenStatus(string $specimenId, string $status): self
    {
        return new self(sprintf(
            'Specimen [%s] is [%s] — result entry requires an ACCEPTED or PROCESSING specimen.',
            $specimenId,
            $status,
        ));
    }

    public static function itemMismatch(string $orderItemId, string $specimenId): self
    {
        return new self(sprintf(
            'Order item [%s] does not belong to the same order as specimen [%s].',
            $orderItemId,
            $specimenId,
        ));
    }

    public function errorCode(): string
    {
        return 'specimen_not_ready_for_result';
    }
}
