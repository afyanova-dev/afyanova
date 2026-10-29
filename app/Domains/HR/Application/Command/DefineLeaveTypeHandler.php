<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\DuplicateLeaveTypeCodeException;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\LeaveType;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles DefineLeaveType command (25-hr-and-workforce-domain.md §6).
 */
final class DefineLeaveTypeHandler
{
    public function __construct(
        private readonly LeaveTypeRepository $leaveTypes,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineLeaveType $command): void
    {
        if ($this->leaveTypes->existsByCode($command->code)) {
            throw DuplicateLeaveTypeCodeException::forCode($command->code);
        }

        $type = LeaveType::define(
            tenantId: $this->context->tenantId(),
            code: $command->code,
            name: $command->name,
            isPaid: $command->isPaid,
            accrualRatePerYear: $command->accrualRatePerYear,
            maxBalance: $command->maxBalance,
            allowNegative: $command->allowNegative,
            carryOverLimit: $command->carryOverLimit,
            isActive: $command->isActive,
            leaveTypeId: $command->leaveTypeId,
        );

        $this->leaveTypes->save($type);
    }
}
