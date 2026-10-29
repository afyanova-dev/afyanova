<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Application\Command\AcceptSpecimen;
use App\Domains\Laboratory\Application\Command\CollectSpecimen;
use App\Domains\Laboratory\Application\Command\ReceiveSpecimen;
use App\Domains\Laboratory\Application\Command\RejectSpecimen;
use App\Domains\Laboratory\Application\Command\RequestRecollection;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * LabSpecimen aggregate policy (ADR-158 D-A/D-J).
 *
 * `create` / `recollect` are collection-side actions (`laboratory.specimens.collect`);
 * `receive` (folding receive / accept / reject) is laboratory-reception-side
 * (`laboratory.specimens.receive`) — a genuinely different actor / facility
 * scope (§35). `view` gates the specimen `#[ProtectedQuery]`s.
 */
final class LabSpecimenPolicy extends BasePolicy
{
    /**
     * `CollectSpecimen` carries no `facilityId` — it comes from the ORDER
     * (ADR-158 D-F), unknown until the handler loads it. Coarse any-facility
     * check only; the handler's `assertActorScopedToOrderFacility` (the
     * ADR-133 refinement) enforces the real per-order-facility scope.
     */
    public function create(User $user, ?CollectSpecimen $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.specimens.collect');
    }

    public function recollect(User $user, LabSpecimen $specimen, ?RequestRecollection $command = null): bool
    {
        return $this->hasScope($user, $specimen, 'laboratory.specimens.collect');
    }

    public function receive(User $user, LabSpecimen $specimen, ReceiveSpecimen|AcceptSpecimen|RejectSpecimen|null $command = null): bool
    {
        return $this->hasScope($user, $specimen, 'laboratory.specimens.receive');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }

    private function hasScope(User $user, LabSpecimen $specimen, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $specimen->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $specimen->facility_id), true);
    }
}
