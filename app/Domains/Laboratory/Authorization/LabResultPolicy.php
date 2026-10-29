<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Application\Command\AmendResult;
use App\Domains\Laboratory\Application\Command\CancelResult;
use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\ReleaseResult;
use App\Domains\Laboratory\Application\Command\ReviewResult;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * LabResult aggregate policy (ADR-159 D-A/D-J).
 *
 * `create` / `review` → `laboratory.results.enter`; `verify` →
 * `laboratory.results.verify`; `release` → `laboratory.results.release`
 * (three finer keys — entry, verification and release are distinct
 * responsibilities, §57/§58/§60, the CP.1 multi-key precedent). `view` gates
 * the result `#[ProtectedQuery]`s.
 */
final class LabResultPolicy extends BasePolicy
{
    /**
     * `RecordResult` carries no `facilityId` — it comes from the SPECIMEN
     * (ADR-159 D-F). Coarse any-facility check; the handler's
     * `assertActorScopedToFacility` (the ADR-133 refinement) enforces the real
     * per-specimen-facility scope.
     */
    public function create(User $user, ?RecordResult $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.results.enter');
    }

    public function review(User $user, LabResult $result, ?ReviewResult $command = null): bool
    {
        return $this->hasScope($user, $result, 'laboratory.results.enter');
    }

    public function verify(User $user, LabResult $result, ?VerifyResult $command = null): bool
    {
        return $this->hasScope($user, $result, 'laboratory.results.verify');
    }

    public function release(User $user, LabResult $result, ?ReleaseResult $command = null): bool
    {
        return $this->hasScope($user, $result, 'laboratory.results.release');
    }

    public function amend(User $user, LabResult $result, AmendResult|CancelResult|null $command = null): bool
    {
        return $this->hasScope($user, $result, 'laboratory.results.amend');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }

    private function hasScope(User $user, LabResult $result, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $result->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $result->facility_id), true);
    }
}
