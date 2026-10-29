<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Plan-coverage-rule policy (ADR-200 D-D). Registered for the `PlanCoverageRule`
 * aggregate — also the `#[ProtectedQuery]` subject for `EvaluateCoverage` /
 * `GetPlanCoverageRules`.
 *
 * `view` — coarse `insurance.access` (the `EvaluateCoverage` / rule reads).
 * `manage` — coarse `insurance.coverage.manage`, tenant-wide: coverage rules
 * are organization-level configuration (§219 / §220), and commercially
 * sensitive (§154 / §281) — a DISTINCT key from `.plans.manage`. The
 * `billing.catalog.manage` precedent (one `.manage` key for the config; the
 * query gates on `.access`).
 */
final class PlanCoverageRulePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.coverage.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
