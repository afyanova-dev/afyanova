<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AbortCase;
use App\Domains\Theatre\Application\Command\AssignTeamMember;
use App\Domains\Theatre\Application\Command\CancelCase;
use App\Domains\Theatre\Application\Command\CompleteCase;
use App\Domains\Theatre\Application\Command\CompleteChecklistStage;
use App\Domains\Theatre\Application\Command\CreateSurgicalCase;
use App\Domains\Theatre\Application\Command\MarkCaseReady;
use App\Domains\Theatre\Application\Command\MarkNoShow;
use App\Domains\Theatre\Application\Command\PostponeCase;
use App\Domains\Theatre\Application\Command\ReplaceTeamMember;
use App\Domains\Theatre\Application\Command\RescheduleSurgicalCase;
use App\Domains\Theatre\Application\Command\ScheduleSurgicalCase;
use App\Domains\Theatre\Application\Command\StartCase;
use App\Domains\Theatre\Application\Command\StartPreOp;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * SurgicalCase aggregate policy (ADR-150 D-A/D-I).
 */
final class SurgicalCasePolicy extends BasePolicy
{
    /**
     * `CreateSurgicalCase` carries no `facilityId` — the facility comes from
     * the ENCOUNTER context (ADR-150 D-H), unknown until the handler loads
     * it. This is a coarse, any-facility check only; the handler's own
     * `assertActorScopedToEncounterFacility` (the ADR-133 refinement
     * precedent) enforces the real per-encounter-facility scope.
     */
    public function create(User $user, ?CreateSurgicalCase $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.cases.create');
    }

    public function schedule(User $user, SurgicalCase $case, ?ScheduleSurgicalCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.schedule');
    }

    public function reschedule(User $user, SurgicalCase $case, ?RescheduleSurgicalCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.schedule');
    }

    public function assignTeamMember(User $user, SurgicalCase $case, ?AssignTeamMember $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.team.manage');
    }

    public function replaceTeamMember(User $user, SurgicalCase $case, ?ReplaceTeamMember $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.team.manage');
    }

    public function completeChecklistStage(User $user, SurgicalCase $case, ?CompleteChecklistStage $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.checklists.manage');
    }

    public function startPreOp(User $user, SurgicalCase $case, ?StartPreOp $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.execute');
    }

    public function markReady(User $user, SurgicalCase $case, ?MarkCaseReady $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.execute');
    }

    public function start(User $user, SurgicalCase $case, ?StartCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.execute');
    }

    public function complete(User $user, SurgicalCase $case, ?CompleteCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.execute');
    }

    public function cancel(User $user, SurgicalCase $case, ?CancelCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.cancel');
    }

    public function postpone(User $user, SurgicalCase $case, ?PostponeCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.cancel');
    }

    public function abort(User $user, SurgicalCase $case, ?AbortCase $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.cancel');
    }

    public function markNoShow(User $user, SurgicalCase $case, ?MarkNoShow $command = null): bool
    {
        return $this->hasCaseScope($user, $case, 'theatre.cases.cancel');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }

    private function hasCaseScope(User $user, SurgicalCase $case, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $case->tenant_id)
            && in_array(
                $permissionKey,
                $this->scope->effectivePermissions($user, (string) $case->facility_id),
                true,
            );
    }
}
