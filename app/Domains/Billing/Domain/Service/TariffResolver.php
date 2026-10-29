<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Service;

use App\Domains\Billing\Contracts\Dto\TariffResolution;
use App\Domains\Billing\Domain\TariffCandidate;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;

/**
 * Pure price-resolution logic (20-billing-and-payments-domain.md §233 / §234;
 * ADR-188 D-E). No I/O — the reader supplies the two date-matched candidates
 * (facility scope + tenant default), this picks:
 *
 *   FACILITY  >  TENANT_DEFAULT  >  NONE (unpriced)
 *
 * Status-agnostic: `$serviceStatus` is carried through onto the result for the
 * caller (Bill.3) to gate on.
 */
final class TariffResolver
{
    public function resolve(
        string $billingServiceId,
        ?string $facilityId,
        string $serviceStatus,
        ?TariffCandidate $facilityCandidate,
        ?TariffCandidate $tenantDefaultCandidate,
    ): TariffResolution {
        if ($facilityCandidate !== null) {
            return new TariffResolution(
                billingServiceId: $billingServiceId,
                facilityId: $facilityId,
                resolvedScope: TariffStatus::SCOPE_FACILITY,
                amount: $facilityCandidate->amount,
                currency: $facilityCandidate->currency,
                effectiveFrom: $facilityCandidate->effectiveFrom,
                tariffRowId: $facilityCandidate->tariffRowId,
                serviceStatus: $serviceStatus,
            );
        }

        if ($tenantDefaultCandidate !== null) {
            return new TariffResolution(
                billingServiceId: $billingServiceId,
                facilityId: $facilityId,
                resolvedScope: TariffStatus::SCOPE_TENANT_DEFAULT,
                amount: $tenantDefaultCandidate->amount,
                currency: $tenantDefaultCandidate->currency,
                effectiveFrom: $tenantDefaultCandidate->effectiveFrom,
                tariffRowId: $tenantDefaultCandidate->tariffRowId,
                serviceStatus: $serviceStatus,
            );
        }

        return new TariffResolution(
            billingServiceId: $billingServiceId,
            facilityId: $facilityId,
            resolvedScope: TariffStatus::SCOPE_NONE,
            amount: null,
            currency: 'TZS',
            effectiveFrom: null,
            tariffRowId: null,
            serviceStatus: $serviceStatus,
        );
    }
}
