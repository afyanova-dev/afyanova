<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\SupplierReader;
use App\Domains\Procurement\Domain\ValueObject\SupplierStatus;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;

final class EloquentSupplierReader implements SupplierReader
{
    public function summary(string $supplierId): SupplierSummary
    {
        $supplier = Supplier::query()->whereKey($supplierId)->first()
            ?? throw NotFound::aggregate(Supplier::class, $supplierId);

        return $this->toSummary($supplier);
    }

    public function list(?string $status = null, ?string $category = null): array
    {
        $query = Supplier::query();

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        if ($category !== null) {
            $query->whereJsonContains('categories', strtoupper(trim($category)));
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (Supplier $s): SupplierSummary => $this->toSummary($s))
                ->all()
        );
    }

    private function toSummary(Supplier $supplier): SupplierSummary
    {
        $categories = $supplier->categories ?? [];

        return new SupplierSummary(
            supplierId: (string) $supplier->id,
            code: (string) $supplier->code,
            name: (string) $supplier->name,
            registrationNumber: $supplier->registration_number,
            contactName: $supplier->contact_name,
            contactEmail: $supplier->contact_email,
            contactPhone: $supplier->contact_phone,
            address: $supplier->address,
            taxIdentifier: $supplier->tax_identifier,
            paymentTerms: $supplier->payment_terms,
            categories: $categories,
            status: (string) $supplier->status,
            statusReason: $supplier->status_reason,
            version: (int) $supplier->version,
            allowedStatuses: SupplierStatus::allowedFrom((string) $supplier->status),
            licenceNumber: $supplier->licence_number,
            licenceAuthority: $supplier->licence_authority,
            licenceExpiryDate: $supplier->licence_expiry_date?->toDateString(),
            licenceExpiryStatus: $this->licenceExpiryStatus($supplier->licence_expiry_date),
        );
    }

    private function licenceExpiryStatus(?CarbonImmutable $expiryDate): string
    {
        if ($expiryDate === null) {
            return 'MISSING';
        }

        $today = CarbonImmutable::today();

        if ($expiryDate->lt($today)) {
            return 'EXPIRED';
        }

        if ($expiryDate->lte($today->addDays(30))) {
            return 'EXPIRING_SOON';
        }

        return 'CURRENT';
    }
}
