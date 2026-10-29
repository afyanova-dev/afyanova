<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\Slot;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for Slot aggregate root.
 */
interface SlotRepository extends Repository
{
    public function getById(string $id): Slot;

    public function getByIdForUpdate(string $id): Slot;

    public function existsAtTime(string $resourceId, CarbonImmutable $startTime): bool;

    /**
     * @param  list<Slot>  $slots
     */
    public function saveMany(array $slots): void;
}
