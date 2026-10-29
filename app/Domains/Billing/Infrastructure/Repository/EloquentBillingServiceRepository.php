<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see BillingServiceRepository} (IC §9; ADR-188 D-A).
 */
final class EloquentBillingServiceRepository extends EloquentRepository implements BillingServiceRepository
{
    protected function model(): string
    {
        return BillingService::class;
    }

    public function getById(string $id): BillingService
    {
        return BillingService::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(BillingService::class, $id);
    }

    public function getByIdForUpdate(string $id): BillingService
    {
        return BillingService::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(BillingService::class, $id);
    }

    public function existsByCode(string $code): bool
    {
        return BillingService::query()->where('code', $code)->exists();
    }
}
