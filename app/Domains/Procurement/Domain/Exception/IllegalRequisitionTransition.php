<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A purchase-requisition status transition was illegal
 * (19-procurement-domain.md §21; ADR-171 D-E). Maps to HTTP 409.
 */
final class IllegalRequisitionTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on a purchase requisition in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_requisition_transition';
    }
}
