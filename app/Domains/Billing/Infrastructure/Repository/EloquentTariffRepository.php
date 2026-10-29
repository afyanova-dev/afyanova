<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\TariffRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see TariffRepository} (IC §9; ADR-188 D-B).
 */
final class EloquentTariffRepository extends EloquentRepository implements TariffRepository
{
    protected function model(): string
    {
        return Tariff::class;
    }

    public function getById(string $id): Tariff
    {
        return Tariff::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Tariff::class, $id);
    }

    public function getByIdForUpdate(string $id): Tariff
    {
        return Tariff::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Tariff::class, $id);
    }

    public function openRowForScope(string $billingServiceId, string $facilityId): ?Tariff
    {
        return Tariff::query()
            ->where('billing_service_id', $billingServiceId)
            ->where('facility_id', $facilityId)
            ->whereNull('effective_to')
            ->first();
    }

    public function openRowForScopeForUpdate(string $billingServiceId, string $facilityId): ?Tariff
    {
        return Tariff::query()
            ->where('billing_service_id', $billingServiceId)
            ->where('facility_id', $facilityId)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();
    }
}
