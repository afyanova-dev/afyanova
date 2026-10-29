<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Exception\TeamAssignmentAlreadyReplaced;
use App\Domains\Theatre\Domain\Model\CaseTeamAssignment;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;

final class ReplaceTeamMemberHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(ReplaceTeamMember $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanAssignTeamMember((string) $case->status);

        $existing = CaseTeamAssignment::query()
            ->where('case_id', $case->id)
            ->whereKey($command->existingAssignmentId)
            ->first();

        if ($existing === null) {
            throw NotFound::aggregate(CaseTeamAssignment::class, $command->existingAssignmentId);
        }

        if ($existing->replaced_at !== null) {
            throw TeamAssignmentAlreadyReplaced::forAssignment($command->existingAssignmentId);
        }

        $now = CarbonImmutable::now();

        $existing->replaced_at = $now;
        $existing->save();

        CaseTeamAssignment::create([
            'id' => $command->newAssignmentId,
            'tenant_id' => $case->tenant_id,
            'facility_id' => $case->facility_id,
            'case_id' => $case->id,
            'staff_user_id' => $command->newStaffUserId,
            'role' => $existing->role,
            'assigned_at' => $now,
            'replaced_at' => null,
        ]);
    }
}
