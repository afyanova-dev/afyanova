<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An RFQ status transition was illegal (ADR-173 D-B / D-C). Maps to HTTP 409.
 */
final class IllegalRfqTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on an RFQ in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_rfq_transition';
    }
}
