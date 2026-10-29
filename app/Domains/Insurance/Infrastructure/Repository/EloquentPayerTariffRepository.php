<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Exception\PayerTariffNotFound;
use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\PayerTariffRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see PayerTariffRepository} (IC §9; ADR-203).
 */
final class EloquentPayerTariffRepository extends EloquentRepository implements PayerTariffRepository
{
    protected function model(): string
    {
        return PayerTariff::class;
    }

    public function getById(string $id): PayerTariff
    {
        return PayerTariff::query()->whereKey($id)->first()
            ?? throw PayerTariffNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): PayerTariff
    {
        return PayerTariff::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw PayerTariffNotFound::id($id);
    }

    public function openRowForScopeForUpdate(string $contractId, string $serviceCategory): ?PayerTariff
    {
        return PayerTariff::query()
            ->where('contract_id', $contractId)
            ->where('service_category', $serviceCategory)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();
    }
}
