<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\CoverageDecision;
use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\Service\CoverageEvaluator;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class EvaluateCoverageHandler
{
    public function __construct(
        private readonly CoverageEvaluator $evaluator,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(EvaluateCoverage $query): CoverageDecision
    {
        if ($query->facilityId !== null) {
            $user = Auth::user();
            if (! $user instanceof User) {
                throw new RuntimeException('EvaluateCoverage dispatched without an authenticated user (IC §6.1).');
            }

            if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $query->facilityId, 'insurance.access')) {
                throw NotFound::aggregate(PlanCoverageRule::class, $query->facilityId);
            }
        }

        return $this->evaluator->evaluate(
            patientId: $query->patientId,
            planId: $query->planId,
            serviceCategory: $query->serviceCategory,
            chargeAmount: $query->chargeAmount,
            onDate: $query->onDate,
            facilityId: $query->facilityId,
        );
    }
}
