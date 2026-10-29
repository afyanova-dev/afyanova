<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\StaffUserLinkSummary;
use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Domains\HR\Domain\StaffUserLinkReader;

/**
 * Eloquent implementation of StaffUserLinkReader (25-hr-and-workforce-domain.md §2, §10; ADR-206).
 */
final class EloquentStaffUserLinkReader implements StaffUserLinkReader
{
    public function findByStaff(string $staffId): ?StaffUserLinkSummary
    {
        /** @var StaffUserLink|null $link */
        $link = StaffUserLink::query()->where('staff_id', $staffId)->first();

        return $link !== null ? $this->toSummary($link) : null;
    }

    public function findByUser(string $userId): ?StaffUserLinkSummary
    {
        /** @var StaffUserLink|null $link */
        $link = StaffUserLink::query()->where('user_id', $userId)->first();

        return $link !== null ? $this->toSummary($link) : null;
    }

    private function toSummary(StaffUserLink $link): StaffUserLinkSummary
    {
        return new StaffUserLinkSummary(
            linkId: $link->id,
            tenantId: $link->tenant_id,
            staffId: $link->staff_id,
            userId: $link->user_id,
            linkedAt: $link->linked_at->toIso8601String(),
            linkedBy: $link->linked_by,
        );
    }
}
