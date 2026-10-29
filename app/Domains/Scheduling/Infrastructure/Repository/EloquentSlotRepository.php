<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\Exception\SlotNotFoundException;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

final class EloquentSlotRepository extends EloquentRepository implements SlotRepository
{
    protected function model(): string
    {
        return Slot::class;
    }

    public function getById(string $id): Slot
    {
        return Slot::query()->whereKey($id)->first()
            ?? throw SlotNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Slot
    {
        return Slot::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw SlotNotFoundException::forId($id);
    }

    public function existsAtTime(string $resourceId, CarbonImmutable $startTime): bool
    {
        return Slot::query()
            ->where('resource_id', $resourceId)
            ->where('start_time', $startTime)
            ->exists();
    }

    public function saveMany(array $slots): void
    {
        foreach ($slots as $slot) {
            $this->save($slot);
        }
    }
}
