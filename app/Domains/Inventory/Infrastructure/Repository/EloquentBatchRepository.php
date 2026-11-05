<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see BatchRepository} (IC §9; ADR-170 D-E).
 */
final class EloquentBatchRepository extends EloquentRepository implements BatchRepository
{
    protected function model(): string
    {
        return Batch::class;
    }

    public function getById(string $id): Batch
    {
        return Batch::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Batch::class, $id);
    }

    public function getByIdForUpdate(string $id): Batch
    {
        return Batch::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Batch::class, $id);
    }

    public function findByItemAndNumber(string $tenantId, string $itemId, string $batchNumber): ?Batch
    {
        return Batch::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('batch_number', $batchNumber)
            ->first();
    }

    public function nextGeneratedBatchNumber(string $tenantId, string $itemId, string $itemCode, int $year): string
    {
        $prefix = $this->normalizedItemPrefix($itemCode);
        $batchPrefix = $prefix.'-'.$year;

        $numbers = Batch::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('batch_number', 'like', $batchPrefix.'%')
            ->pluck('batch_number');

        $next = 1;
        foreach ($numbers as $number) {
            if (preg_match('/-(\d{4})(\d{2})$/', (string) $number, $match) === 1
                && (int) $match[1] === $year) {
                $next = max($next, (int) $match[2] + 1);
            }
        }

        return $batchPrefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }

    public function isGeneratedBatchNumber(string $itemCode, string $batchNumber, int $year): bool
    {
        $prefix = $this->normalizedItemPrefix($itemCode).'-'.$year;

        return preg_match('/^'.preg_quote($prefix, '/').'\d{2}$/', strtoupper(trim($batchNumber))) === 1;
    }

    private function normalizedItemPrefix(string $itemCode): string
    {
        $code = strtoupper(trim($itemCode));
        $prefix = preg_replace('/([A-Z])-(?=\d)/', '$1', $code) ?? $code;
        $prefix = preg_replace('/\s+/', '', $prefix) ?? $prefix;

        return str_replace('_', '-', $prefix);
    }
}
