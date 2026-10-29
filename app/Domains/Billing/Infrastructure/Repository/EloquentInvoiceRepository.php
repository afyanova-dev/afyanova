<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see InvoiceRepository} (IC §9; ADR-190 D-A).
 */
final class EloquentInvoiceRepository extends EloquentRepository implements InvoiceRepository
{
    protected function model(): string
    {
        return Invoice::class;
    }

    public function getById(string $id): Invoice
    {
        return Invoice::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Invoice::class, $id);
    }

    public function getByIdForUpdate(string $id): Invoice
    {
        return Invoice::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Invoice::class, $id);
    }
}
