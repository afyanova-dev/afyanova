<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetClinicalPlanHandler
{
    public function __construct(
        private readonly ClinicalPlanReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetClinicalPlan $query): ClinicalPlanSummary
    {
        $summary = $this->reader->summary($query->planId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetClinicalPlan dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.plans.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalPlan::class, $query->planId);
        }

        return $summary;
    }
}
