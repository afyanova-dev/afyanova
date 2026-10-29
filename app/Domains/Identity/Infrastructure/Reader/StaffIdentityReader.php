<?php

declare(strict_types=1);

namespace App\Domains\Identity\Infrastructure\Reader;

use App\Domains\Identity\Domain\Model\StaffIdentity;

final class StaffIdentityReader
{
    public function isStaff(string $userId): bool
    {
        return StaffIdentity::query()->where('user_id', $userId)->where('is_staff', true)->exists();
    }

    public function forUser(string $userId): ?StaffIdentity
    {
        return StaffIdentity::query()->where('user_id', $userId)->first();
    }
}
