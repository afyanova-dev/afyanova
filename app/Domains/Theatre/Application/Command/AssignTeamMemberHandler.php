<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\CaseTeamAssignment;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Carbon\CarbonImmutable;

final class AssignTeamMemberHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(AssignTeamMember $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanAssignTeamMember((string) $case->status);

        CaseTeamAssignment::create([
            'id' => $command->assignmentId,
            'tenant_id' => $case->tenant_id,
            'facility_id' => $case->facility_id,
            'case_id' => $case->id,
            'staff_user_id' => $command->staffUserId,
            'role' => strtoupper(trim($command->role)),
            'assigned_at' => CarbonImmutable::now(),
            'replaced_at' => null,
        ]);
    }
}
