<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Authorization;

use App\Domains\Radiology\Application\Command\AbortImagingStudy;
use App\Domains\Radiology\Application\Command\CancelImagingStudy;
use App\Domains\Radiology\Application\Command\CompleteImagingStudy;
use App\Domains\Radiology\Application\Command\MarkStudyReady;
use App\Domains\Radiology\Application\Command\RecordImagingNoShow;
use App\Domains\Radiology\Application\Command\ScheduleImagingStudy;
use App\Domains\Radiology\Application\Command\StartImagingStudy;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ImagingStudy aggregate policy (ADR-166 D-A/D-G). One key backs every write:
 * `radiology.studies.perform` (§120 verbatim). `create` is the class-level
 * check for `ScheduleImagingStudy` (which carries no subject — the facility
 * comes from the order, resolved in the handler; the handler's own ADR-133
 * refinement enforces the real per-order-facility scope — the `ImagingOrderPolicy::create`
 * precedent). `perform` is the instance-level check for the six study
 * transitions. `view` gates every `#[ProtectedQuery]` in the increment.
 */
final class ImagingStudyPolicy extends BasePolicy
{
    public function create(User $user, ?ScheduleImagingStudy $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.studies.perform');
    }

    public function perform(
        User $user,
        ImagingStudy $study,
        MarkStudyReady|StartImagingStudy|CompleteImagingStudy|CancelImagingStudy|RecordImagingNoShow|AbortImagingStudy|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $study->tenant_id)
            && in_array('radiology.studies.perform', $this->scope->effectivePermissions($user, (string) $study->facility_id), true);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.access');
    }
}
