<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentPurchaseOrderRepository extends EloquentRepository implements PurchaseOrderRepository
{
    protected function model(): string
    {
        return PurchaseOrder::class;
    }

    public function getById(string $id): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PurchaseOrder::class, $id);
    }

    public function getByIdForUpdate(string $id): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PurchaseOrder::class, $id);
    }

    public function findByNumberForUpdate(string $poNumber): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('po_number', $poNumber)
            ->lockForUpdate()
            ->first();
    }
}
