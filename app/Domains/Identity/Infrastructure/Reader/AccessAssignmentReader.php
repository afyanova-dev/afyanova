<?php

declare(strict_types=1);

namespace App\Domains\Identity\Infrastructure\Reader;

use App\Domains\Identity\Domain\Model\AccessAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-side over `idn_access_assignments` (docs/00-foundation/03-identity-rbac.md
 * §6, §37). Eloquent permitted in `Infrastructure/` (IC §9).
 */
final class AccessAssignmentReader
{
    /** @return Collection<int, AccessAssignment> */
    public function forUser(User $user): Collection
    {
        return AccessAssignment::query()
            ->with('role')
            ->where('user_id', $user->getKey())
            ->get();
    }

    /** @return Collection<int, AccessAssignment> the ones currently in force */
    public function effectiveForUser(User $user): Collection
    {
        return $this->forUser($user)->filter(static fn (AccessAssignment $a): bool => $a->isEffective())->values();
    }
}
