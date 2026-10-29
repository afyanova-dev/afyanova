<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Authorization;

use App\Domains\Radiology\Application\Command\AmendRadiologyReport;
use App\Domains\Radiology\Application\Command\CancelRadiologyReport;
use App\Domains\Radiology\Application\Command\CreateRadiologyReport;
use App\Domains\Radiology\Application\Command\MarkReportReviewed;
use App\Domains\Radiology\Application\Command\ReleaseRadiologyReport;
use App\Domains\Radiology\Application\Command\UpdateRadiologyReport;
use App\Domains\Radiology\Application\Command\VerifyRadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * RadiologyReport aggregate policy (ADR-167 D-A/D-F, ADR-168 D-G). Keys:
 * `radiology.reports.create` backs `create` (class-level, for
 * `CreateRadiologyReport` whose facility comes from the study — the handler's
 * ADR-133 refinement enforces the real per-study-facility scope) + `author`
 * (update / review); `radiology.reports.verify` backs `verify`;
 * `radiology.reports.release` backs `release`; `radiology.reports.amend` backs
 * `amend` (correction / addendum / cancellation). `view` gates every
 * `#[ProtectedQuery]` on reports + amendments.
 */
final class RadiologyReportPolicy extends BasePolicy
{
    public function create(User $user, ?CreateRadiologyReport $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.reports.create');
    }

    public function author(User $user, RadiologyReport $report, UpdateRadiologyReport|MarkReportReviewed|null $command = null): bool
    {
        return $this->hasScope($user, $report, 'radiology.reports.create');
    }

    public function verify(User $user, RadiologyReport $report, ?VerifyRadiologyReport $command = null): bool
    {
        return $this->hasScope($user, $report, 'radiology.reports.verify');
    }

    public function release(User $user, RadiologyReport $report, ?ReleaseRadiologyReport $command = null): bool
    {
        return $this->hasScope($user, $report, 'radiology.reports.release');
    }

    public function amend(User $user, RadiologyReport $report, AmendRadiologyReport|CancelRadiologyReport|null $command = null): bool
    {
        return $this->hasScope($user, $report, 'radiology.reports.amend');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.access');
    }

    private function hasScope(User $user, RadiologyReport $report, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $report->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $report->facility_id), true);
    }
}
