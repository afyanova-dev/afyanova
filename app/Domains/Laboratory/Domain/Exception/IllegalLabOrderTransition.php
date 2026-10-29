<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A laboratory-order status transition was illegal (14-laboratory-domain.md
 * §24, §155; ADR-157 D-E). Maps to HTTP 409.
 */
final class IllegalLabOrderTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on laboratory order currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_lab_order_transition';
    }
}
