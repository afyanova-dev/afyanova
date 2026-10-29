<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A pharmacy-order command was dispatched against a record in a status that
 * does not permit it (16-pharmacy-domain.md §122 / §249; ADR-182 D-C). Maps to
 * HTTP 409.
 */
final class IllegalPharmacyOrderStatusTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from pharmacy-order status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_pharmacy_order_status_transition';
    }
}
