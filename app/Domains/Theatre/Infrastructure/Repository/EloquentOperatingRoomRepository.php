<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of OperatingRoomRepository (ADR-150 D-C).
 */
final class EloquentOperatingRoomRepository extends EloquentRepository implements OperatingRoomRepository
{
    protected function model(): string
    {
        return OperatingRoom::class;
    }

    public function getById(string $id): OperatingRoom
    {
        return OperatingRoom::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(OperatingRoom::class, $id);
    }

    public function getByIdForUpdate(string $id): OperatingRoom
    {
        return OperatingRoom::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(OperatingRoom::class, $id);
    }
}
