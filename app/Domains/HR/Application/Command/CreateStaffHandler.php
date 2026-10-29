<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\PersonRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Infrastructure\Sequence\StaffNumberSequence;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles CreateStaff command (ADR-206).
 */
final class CreateStaffHandler
{
    public function __construct(
        private readonly StaffRepository $staff,
        private readonly PersonRepository $persons,
        private readonly StaffNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateStaff $command): void
    {
        $tenantId = $this->context->tenantId();

        // Assert person exists
        $this->persons->getById($command->personId);

        $staffNumber = $this->sequence->next($tenantId);

        $staff = Staff::create(
            tenantId: $tenantId,
            personId: $command->personId,
            staffNumber: $staffNumber,
            primaryCadreId: $command->primaryCadreId,
            primaryPositionId: $command->primaryPositionId,
        );
        $staff->id = $command->staffId;

        $this->staff->save($staff);
    }
}
