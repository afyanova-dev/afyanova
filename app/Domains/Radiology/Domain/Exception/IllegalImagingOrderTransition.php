<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An imaging-order status transition was illegal (15-radiology-domain.md §20,
 * §104, §161; ADR-165 D-F). Maps to HTTP 409.
 */
final class IllegalImagingOrderTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on imaging order currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_imaging_order_transition';
    }
}
