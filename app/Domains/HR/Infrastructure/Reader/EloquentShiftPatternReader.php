<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\ShiftPatternSummary;
use App\Domains\HR\Domain\Exception\ShiftPatternNotFoundException;
use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Domains\HR\Domain\ShiftPatternReader;

/**
 * Eloquent implementation of ShiftPatternReader (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
final class EloquentShiftPatternReader implements ShiftPatternReader
{
    public function summary(string $shiftPatternId): ShiftPatternSummary
    {
        /** @var ShiftPattern|null $pattern */
        $pattern = ShiftPattern::query()->whereKey($shiftPatternId)->first();

        if ($pattern === null) {
            throw ShiftPatternNotFoundException::forId($shiftPatternId);
        }

        return $this->toSummary($pattern);
    }

    public function listByFacility(string $facilityId, bool $activeOnly = true): array
    {
        $query = ShiftPattern::query()->where('facility_id', $facilityId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return array_values($query
            ->orderBy('code')
            ->get()
            ->map(fn (ShiftPattern $p) => $this->toSummary($p))
            ->all());
    }

    private function toSummary(ShiftPattern $p): ShiftPatternSummary
    {
        return new ShiftPatternSummary(
            shiftPatternId: $p->id,
            tenantId: $p->tenant_id,
            facilityId: $p->facility_id,
            code: $p->code,
            name: $p->name,
            startTime: (string) $p->start_time,
            endTime: (string) $p->end_time,
            breakDurationMinutes: $p->break_minutes,
            isOvernight: $p->is_overnight,
            colorCode: null,
            isActive: $p->is_active,
            version: $p->version,
        );
    }
}
