<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Authorization;

use App\Models\User;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Shared\Authorization\BasePolicy;

final class ReportingPolicy extends BasePolicy
{
    public function viewDashboard(User $user): bool
    {
        return $this->coarse($user, 'reporting.dashboard.view');
    }

    public function runReport(User $user): bool
    {
        return $this->coarse($user, 'reporting.report.run');
    }

    public function defineReport(User $user): bool
    {
        return $this->coarse($user, 'reporting.report.define');
    }

    public function scheduleReport(User $user): bool
    {
        return $this->coarse($user, 'reporting.report.schedule');
    }

    public function queryMart(User $user): bool
    {
        return $this->coarse($user, 'reporting.selfservice.query');
    }

    public function curateMart(User $user): bool
    {
        return $this->coarse($user, 'reporting.mart.curate');
    }

    public function buildStatutory(User $user): bool
    {
        return $this->coarse($user, 'reporting.statutory.build');
    }

    public function reviewStatutory(User $user, ?StatutoryReturn $return = null): bool
    {
        if ($return !== null) {
            return $this->sameTenant($user, (string) $return->tenant_id)
                && $this->permits($user, 'reporting.statutory.review');
        }

        return $this->coarse($user, 'reporting.statutory.review');
    }

    public function submitStatutory(User $user, ?StatutoryReturn $return = null): bool
    {
        if ($return !== null) {
            return $this->sameTenant($user, (string) $return->tenant_id)
                && $this->permits($user, 'reporting.statutory.submit');
        }

        return $this->coarse($user, 'reporting.statutory.submit');
    }

    public function manageMetric(User $user): bool
    {
        return $this->coarse($user, 'reporting.metric.manage');
    }

    public function admin(User $user): bool
    {
        return $this->coarse($user, 'reporting.admin.manage')
            || $this->coarse($user, 'reporting.projection.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
