<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetTariff` was dispatched against an INACTIVE billing service
 * (ADR-188 D-C / F5 — pricing requires an ACTIVE service; reactivate first).
 * Maps to HTTP 409.
 */
final class BillingServiceInactive extends DomainException
{
    public static function id(string $billingServiceId): self
    {
        return new self(sprintf(
            'Billing service [%s] is INACTIVE — SetTariff requires an ACTIVE service.',
            $billingServiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'billing_service_inactive';
    }
}
