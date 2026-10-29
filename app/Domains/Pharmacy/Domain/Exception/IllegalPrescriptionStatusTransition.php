<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A prescription command was dispatched against a record in a status that does
 * not permit it (16-pharmacy-domain.md §20 / §250; ADR-181 D-D). Maps to HTTP 409.
 */
final class IllegalPrescriptionStatusTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from prescription status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_prescription_status_transition';
    }
}
