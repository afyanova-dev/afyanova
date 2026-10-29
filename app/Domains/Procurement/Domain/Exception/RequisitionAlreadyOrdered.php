<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

final class RequisitionAlreadyOrdered extends DomainException
{
    public static function forRequisition(string $requisitionId): self
    {
        return new self(sprintf(
            'Purchase requisition [%s] already has an active purchase order.',
            $requisitionId,
        ));
    }

    public function errorCode(): string
    {
        return 'requisition_already_ordered';
    }
}
