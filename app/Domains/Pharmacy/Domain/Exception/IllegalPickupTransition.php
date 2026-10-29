<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A pickup command was dispatched against a `Pickup` in a status that does not
 * permit it (16-pharmacy-domain.md §29; ADR-186 D-D). Maps to HTTP 409.
 */
final class IllegalPickupTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from pickup status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_pickup_transition';
    }
}
