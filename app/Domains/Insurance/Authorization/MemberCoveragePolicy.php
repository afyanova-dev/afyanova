<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Member-coverage policy (ADR-199 D-D). Registered for the `MemberCoverage`
 * aggregate.
 *
 * `view` — coarse `insurance.access`. `manage` — coarse
 * `insurance.members.manage`, tenant-wide: a patient's insurance membership is
 * not facility-specific (§156; §155's facility scope is an Ins.6 claims /
 * network refinement).
 */
final class MemberCoveragePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.members.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
