<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RegisterSupplier` named a `code` that already belongs to a non-BLACKLISTED
 * supplier in the tenant (19-procurement-domain.md §165; ADR-171 D-C — the
 * CP.1 / Lab.1 catalog code-uniqueness precedent). Maps to HTTP 409.
 */
final class SupplierAlreadyExists extends DomainException
{
    public static function forCode(string $tenantId, string $code): self
    {
        return new self(sprintf('A supplier with code [%s] already exists in tenant [%s].', $code, $tenantId));
    }

    public function errorCode(): string
    {
        return 'supplier_already_exists';
    }
}
