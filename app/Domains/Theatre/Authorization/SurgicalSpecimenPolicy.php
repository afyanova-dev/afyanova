<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\HandOffSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\LabelSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\MarkSurgicalSpecimenReceived;
use App\Domains\Theatre\Application\Command\RecordSurgicalSpecimen;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * SurgicalSpecimen aggregate policy (ADR-164 D-C/D-F). Two keys:
 * `theatre.specimens.record` backs `create` + `record` (label);
 * `theatre.specimens.handoff` backs `handoff` (hand-off + mark-received —
 * §35's "different actor" reasoning: often a courier / lab liaison).
 */
final class SurgicalSpecimenPolicy extends BasePolicy
{
    public function create(User $user, ?RecordSurgicalSpecimen $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.specimens.record');
    }

    public function record(User $user, SurgicalSpecimen $specimen, ?LabelSurgicalSpecimen $command = null): bool
    {
        return $this->hasScope($user, $specimen, 'theatre.specimens.record');
    }

    public function handoff(User $user, SurgicalSpecimen $specimen, HandOffSurgicalSpecimen|MarkSurgicalSpecimenReceived|null $command = null): bool
    {
        return $this->hasScope($user, $specimen, 'theatre.specimens.handoff');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }

    private function hasScope(User $user, SurgicalSpecimen $specimen, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $specimen->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $specimen->facility_id), true);
    }
}
