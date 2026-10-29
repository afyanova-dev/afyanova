<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientClinicalPlansHandler
{
    public function __construct(
        private readonly ClinicalPlanReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ClinicalPlanSummary>
     */
    public function __invoke(GetPatientClinicalPlans $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientClinicalPlans dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.plans.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forPatient($query->patientId, $query->status, $inScopeFacilityIds);
    }
}
