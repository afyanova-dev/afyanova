<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\ReinstateStaff;
use App\Domains\HR\Application\Command\StartEmployment;
use App\Domains\HR\Application\Command\SuspendStaff;
use App\Domains\HR\Application\Command\TerminateStaff;
use App\Domains\Identity\Contracts\LinkedStaffLifecycleApi;
use App\Shared\Audit\AuditLogger;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Facades\DB;

final class FacilityStaffLifecycleService
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly LinkedStaffLifecycleApi $linkedAccess,
        private readonly FacilityStaffDirectoryService $directory,
    ) {}

    /**
     * Complete onboarding and, when the actor may manage employment, start a
     * due draft employment in the same transaction. Future-dated drafts are
     * left untouched for the separate employment-start action.
     */
    public function onboard(
        string $tenantId,
        string $staffId,
        string $joinedDate,
        ?string $cadreId,
        ?string $positionId,
        bool $startDraftEmployment,
    ): ?string {
        return DB::transaction(function () use (
            $tenantId,
            $staffId,
            $joinedDate,
            $cadreId,
            $positionId,
            $startDraftEmployment,
        ): ?string {
            $this->commands->dispatch(new OnboardStaff(
                staffId: $staffId,
                joinedDate: $joinedDate,
                cadreId: $cadreId,
                positionId: $positionId,
            ));

            if (! $startDraftEmployment) {
                return null;
            }

            $employmentId = $this->directory->startableDraftEmploymentId($tenantId, $staffId, $joinedDate);
            if ($employmentId === null) {
                return null;
            }

            $this->commands->dispatch(new StartEmployment(
                employmentId: $employmentId,
                startDate: $joinedDate,
            ));

            return $employmentId;
        });
    }

    public function suspend(string $tenantId, string $staffId, string $actorId, string $reason, AuditLogger $audit): void
    {
        DB::transaction(function () use ($tenantId, $staffId, $actorId, $reason, $audit): void {
            $this->linkedAccess->lockLinkedUserForLifecycle($tenantId, $staffId);
            $this->commands->dispatch(new SuspendStaff(staffId: $staffId, reason: $reason));
            $this->linkedAccess->suspendForStaff(
                tenantId: $tenantId,
                staffId: $staffId,
                actorId: $actorId,
                reason: $reason,
                audit: $audit,
            );
        });
    }

    public function reinstate(string $tenantId, string $staffId, string $actorId, AuditLogger $audit): void
    {
        DB::transaction(function () use ($tenantId, $staffId, $actorId, $audit): void {
            $this->linkedAccess->lockLinkedUserForLifecycle($tenantId, $staffId);
            $this->commands->dispatch(new ReinstateStaff(staffId: $staffId));
            $this->linkedAccess->restoreForStaff(
                tenantId: $tenantId,
                staffId: $staffId,
                actorId: $actorId,
                audit: $audit,
            );
        });
    }

    public function terminate(
        string $tenantId,
        string $staffId,
        string $actorId,
        string $terminatedDate,
        string $reason,
        AuditLogger $audit,
    ): void {
        DB::transaction(function () use ($tenantId, $staffId, $actorId, $terminatedDate, $reason, $audit): void {
            $this->linkedAccess->lockLinkedUserForLifecycle($tenantId, $staffId);
            $this->commands->dispatch(new TerminateStaff(
                staffId: $staffId,
                terminatedDate: $terminatedDate,
                reason: $reason,
            ));
            $this->linkedAccess->revokeAllAccessForStaff(
                tenantId: $tenantId,
                staffId: $staffId,
                actorId: $actorId,
                reason: $reason,
                audit: $audit,
            );
        });
    }
}
