<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class LabExecutionPolicy extends BasePolicy
{
    public function execute(User $user, LabExecution $execution): bool
    {
        return $this->sameTenant($user, (string) $execution->tenant_id)
            && $this->scope->hasFacilityPermissionInContext(
                $user,
                $this->context,
                (string) $execution->facility_id,
                'laboratory.results.enter',
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }
}
