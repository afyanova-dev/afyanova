<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Domains\HR\Domain\StaffUserLinkRepository;
use App\Shared\Events\OutboxWriter;

/**
 * Eloquent implementation of StaffUserLinkRepository (25-hr-and-workforce-domain.md §2; ADR-206).
 */
final class EloquentStaffUserLinkRepository implements StaffUserLinkRepository
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function getForStaff(string $staffId): ?StaffUserLink
    {
        return StaffUserLink::query()->where('staff_id', $staffId)->first();
    }

    public function getForStaffForUpdate(string $staffId): ?StaffUserLink
    {
        return StaffUserLink::query()->where('staff_id', $staffId)->lockForUpdate()->first();
    }

    public function getForUser(string $userId): ?StaffUserLink
    {
        return StaffUserLink::query()->where('user_id', $userId)->first();
    }

    public function existsForStaff(string $staffId): bool
    {
        return StaffUserLink::query()->where('staff_id', $staffId)->exists();
    }

    public function existsForUser(string $userId): bool
    {
        return StaffUserLink::query()->where('user_id', $userId)->exists();
    }

    public function save(StaffUserLink $link): void
    {
        $link->save();

        $this->outbox->writeAll($link->releaseEvents());
    }

    public function delete(StaffUserLink $link): void
    {
        $link->delete();

        $this->outbox->writeAll($link->releaseEvents());
    }
}
