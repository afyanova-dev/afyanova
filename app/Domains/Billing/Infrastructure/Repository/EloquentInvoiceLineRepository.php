<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\Model\InvoiceLine;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see InvoiceLineRepository} (IC §9; ADR-190 D-B).
 */
final class EloquentInvoiceLineRepository extends EloquentRepository implements InvoiceLineRepository
{
    protected function model(): string
    {
        return InvoiceLine::class;
    }

    public function getById(string $id): InvoiceLine
    {
        return InvoiceLine::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(InvoiceLine::class, $id);
    }

    public function getByIdForUpdate(string $id): InvoiceLine
    {
        return InvoiceLine::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(InvoiceLine::class, $id);
    }

    public function liveLineExistsForCharge(string $chargeId): bool
    {
        return InvoiceLine::query()
            ->where('charge_id', $chargeId)
            ->where('line_active', true)
            ->exists();
    }

    public function liveLineForChargeOnInvoice(string $invoiceId, string $chargeId): ?InvoiceLine
    {
        return InvoiceLine::query()
            ->where('invoice_id', $invoiceId)
            ->where('charge_id', $chargeId)
            ->where('line_active', true)
            ->lockForUpdate()
            ->first();
    }

    public function liveLinesForInvoiceForUpdate(string $invoiceId): array
    {
        return array_values(
            InvoiceLine::query()
                ->where('invoice_id', $invoiceId)
                ->where('line_active', true)
                ->orderBy('recorded_at')->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all()
        );
    }

    public function liveClaimedChargeIds(array $chargeIds): array
    {
        if ($chargeIds === []) {
            return [];
        }

        return array_values(
            InvoiceLine::query()
                ->whereIn('charge_id', $chargeIds)
                ->where('line_active', true)
                ->pluck('charge_id')
                ->map(static fn ($id): string => (string) $id)
                ->all()
        );
    }
}
