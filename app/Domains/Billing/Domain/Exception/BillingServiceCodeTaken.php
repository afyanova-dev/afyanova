<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddBillingService` used a `code` already taken in the tenant
 * (20-billing-and-payments-domain.md §236; ADR-188 D-A — `unique (tenant_id,
 * code)`). Maps to HTTP 409.
 */
final class BillingServiceCodeTaken extends DomainException
{
    public static function code(string $code): self
    {
        return new self(sprintf('A billing service with code [%s] already exists in this tenant.', $code));
    }

    public function errorCode(): string
    {
        return 'billing_service_code_taken';
    }
}
