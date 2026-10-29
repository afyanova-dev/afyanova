<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A supplier lifecycle transition was illegal (19-procurement-domain.md §12,
 * §14, §15; ADR-171 D-C). Maps to HTTP 409.
 */
final class IllegalSupplierTransition extends DomainException
{
    public static function from(string $currentStatus, string $targetStatus): self
    {
        return new self(sprintf(
            'Cannot move a supplier from [%s] to [%s] (§12; ADR-171 D-C).',
            $currentStatus,
            $targetStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_supplier_transition';
    }
}
