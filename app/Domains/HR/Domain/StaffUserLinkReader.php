<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\StaffUserLinkSummary;

/**
 * Read-side port for StaffUserLink queries (25-hr-and-workforce-domain.md §2, §10; ADR-206).
 */
interface StaffUserLinkReader
{
    public function findByStaff(string $staffId): ?StaffUserLinkSummary;

    public function findByUser(string $userId): ?StaffUserLinkSummary;
}
