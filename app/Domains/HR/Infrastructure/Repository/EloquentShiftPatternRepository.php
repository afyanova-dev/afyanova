<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\ShiftPatternNotFoundException;
use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Domains\HR\Domain\ShiftPatternRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of ShiftPatternRepository (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class EloquentShiftPatternRepository extends EloquentRepository implements ShiftPatternRepository
{
    protected function model(): string
    {
        return ShiftPattern::class;
    }

    public function getById(string $id): ShiftPattern
    {
        return ShiftPattern::query()->whereKey($id)->first()
            ?? throw ShiftPatternNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): ShiftPattern
    {
        return ShiftPattern::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw ShiftPatternNotFoundException::forId($id);
    }

    public function findByFacilityAndCode(string $facilityId, string $code): ?ShiftPattern
    {
        return ShiftPattern::query()
            ->where('facility_id', $facilityId)
            ->where('code', strtoupper(trim($code)))
            ->first();
    }

    public function existsById(string $id): bool
    {
        return ShiftPattern::query()->whereKey($id)->exists();
    }
}
