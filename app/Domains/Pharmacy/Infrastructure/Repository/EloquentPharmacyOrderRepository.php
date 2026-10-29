<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Repository;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PharmacyOrderRepository} (IC §9; ADR-182 D-C).
 */
final class EloquentPharmacyOrderRepository extends EloquentRepository implements PharmacyOrderRepository
{
    protected function model(): string
    {
        return PharmacyOrder::class;
    }

    public function getById(string $id): PharmacyOrder
    {
        return PharmacyOrder::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PharmacyOrder::class, $id);
    }

    public function getByIdForUpdate(string $id): PharmacyOrder
    {
        return PharmacyOrder::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PharmacyOrder::class, $id);
    }

    public function hasOpenOrderForPrescription(string $prescriptionId): bool
    {
        return PharmacyOrder::query()
            ->where('prescription_id', $prescriptionId)
            ->whereNotIn('status', PharmacyOrderStatus::TERMINAL)
            ->exists();
    }
}
