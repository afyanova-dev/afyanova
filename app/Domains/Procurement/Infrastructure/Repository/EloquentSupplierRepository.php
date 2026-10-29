<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentSupplierRepository extends EloquentRepository implements SupplierRepository
{
    protected function model(): string
    {
        return Supplier::class;
    }

    public function getById(string $id): Supplier
    {
        return Supplier::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Supplier::class, $id);
    }

    public function getByIdForUpdate(string $id): Supplier
    {
        return Supplier::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Supplier::class, $id);
    }

    public function findActiveCode(string $tenantId, string $code): ?Supplier
    {
        return Supplier::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('status', '!=', Supplier::STATUS_BLACKLISTED)
            ->first();
    }
}
