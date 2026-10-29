<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\BillingServiceSummary;
use App\Domains\Billing\Contracts\Dto\TariffResolution;

/**
 * Read-side port for the billing-service catalog + the tariff resolver
 * (IC §6.2 / §6.3; ADR-188 D-D / D-E). Returns DTOs only.
 */
interface BillingCatalogReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\BillingServiceNotFound` if absent (RLS). */
    public function serviceSummary(string $billingServiceId): BillingServiceSummary;

    public function serviceExists(string $billingServiceId): bool;

    /**
     * The current `category` of the billing service with this `code`, or null
     * if no such service (ADR-201 D-D — resolves a snapshot
     * `billing_service_code` on an invoice line to its category).
     */
    public function categoryForCode(string $code): ?string;

    /**
     * @return list<BillingServiceSummary> newest first
     */
    public function searchServices(?string $category, ?string $status): array;

    /**
     * Resolve the base price for a service at a facility on a date (default:
     * today). `$facilityId` null → the tenant default only. An unknown service
     * id → `BillingServiceNotFound`; a known service with no effective price →
     * `resolvedScope = NONE`, `amount = null`.
     */
    public function resolveTariff(string $billingServiceId, ?string $facilityId, ?string $onDate): TariffResolution;

    /**
     * One resolution per catalog service (ACTIVE or INACTIVE), for a facility
     * (override-or-default) or the tenant default when `$facilityId` is null.
     *
     * @return list<TariffResolution>
     */
    public function priceList(?string $facilityId, ?string $onDate): array;

    /**
     * Every tariff row ever written for a service, newest `effective_from`
     * first, both scopes interleaved (the price history the admin audits).
     * Amounts are immutable snapshots — a row never changes after recording.
     *
     * @return list<TariffHistoryEntry>
     */
    public function tariffHistory(string $billingServiceId): array;
}
