<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\EmploymentRepository;
use App\Domains\HR\Domain\Model\Employment;
use App\Domains\HR\Domain\PositionRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles CreateEmployment command (ADR-206).
 * Creates contract in DRAFT status; emits no domain event.
 */
final class CreateEmploymentHandler
{
    public function __construct(
        private readonly EmploymentRepository $employments,
        private readonly StaffRepository $staff,
        private readonly PositionRepository $positions,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateEmployment $command): void
    {
        // Assert staff and position exist
        $this->staff->getById($command->staffId);
        $this->positions->getById($command->positionId);

        $employment = Employment::createDraft(
            tenantId: $this->context->tenantId(),
            staffId: $command->staffId,
            positionId: $command->positionId,
            contractType: $command->contractType,
            startDate: $command->startDate,
            fte: $command->fte,
            endDate: $command->endDate,
            probationEndDate: $command->probationEndDate,
            noticePeriodDays: $command->noticePeriodDays,
            termsSummary: $command->termsSummary,
        );
        $employment->id = $command->employmentId;

        $this->employments->save($employment);
    }
}
