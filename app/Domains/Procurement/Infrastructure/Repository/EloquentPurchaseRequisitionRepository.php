<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentPurchaseRequisitionRepository extends EloquentRepository implements PurchaseRequisitionRepository
{
    protected function model(): string
    {
        return PurchaseRequisition::class;
    }

    public function getById(string $id): PurchaseRequisition
    {
        return PurchaseRequisition::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PurchaseRequisition::class, $id);
    }

    public function getByIdForUpdate(string $id): PurchaseRequisition
    {
        return PurchaseRequisition::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PurchaseRequisition::class, $id);
    }
}
