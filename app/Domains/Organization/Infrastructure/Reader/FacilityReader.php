<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Reader;

use App\Domains\Organization\Domain\Model\Facility;

final class FacilityReader
{
    public function find(string $facilityId): ?Facility
    {
        return Facility::query()->find($facilityId);
    }

    public function exists(string $facilityId): bool
    {
        return Facility::query()->whereKey($facilityId)->exists();
    }

    /** @return list<array{id: string, name: string}> active facilities in the tenant */
    public function activeList(): array
    {
        return array_values(Facility::query()
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Facility $f): array => ['id' => (string) $f->id, 'name' => (string) $f->name])
            ->all());
    }
}
