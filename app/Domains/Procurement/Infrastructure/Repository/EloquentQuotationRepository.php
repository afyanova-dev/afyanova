<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentQuotationRepository extends EloquentRepository implements QuotationRepository
{
    protected function model(): string
    {
        return Quotation::class;
    }

    public function getById(string $id): Quotation
    {
        return Quotation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Quotation::class, $id);
    }

    public function getByIdForUpdate(string $id): Quotation
    {
        return Quotation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Quotation::class, $id);
    }

    public function findByRfqAndSupplier(string $rfqId, string $supplierId): ?Quotation
    {
        return Quotation::query()
            ->where('rfq_id', $rfqId)
            ->where('supplier_id', $supplierId)
            ->first();
    }

    public function lockAllForRfq(string $rfqId): array
    {
        return array_values(
            Quotation::query()
                ->where('rfq_id', $rfqId)
                ->orderBy('quotation_number')
                ->lockForUpdate()
                ->get()
                ->all()
        );
    }
}
