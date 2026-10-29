<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\StaffUserLink;

/**
 * Write-side repository port for StaffUserLink (25-hr-and-workforce-domain.md §2; ADR-206).
 */
interface StaffUserLinkRepository
{
    public function getForStaff(string $staffId): ?StaffUserLink;

    public function getForStaffForUpdate(string $staffId): ?StaffUserLink;

    public function getForUser(string $userId): ?StaffUserLink;

    public function existsForStaff(string $staffId): bool;

    public function existsForUser(string $userId): bool;

    public function save(StaffUserLink $link): void;

    public function delete(StaffUserLink $link): void;
}
