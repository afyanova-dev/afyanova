<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Domain\PrescribingDecisionReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientPrescribingDecisionsHandler
{
    public function __construct(
        private readonly PrescribingDecisionReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<PrescribingDecisionSummary>
     */
    public function __invoke(GetPatientPrescribingDecisions $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientPrescribingDecisions dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.prescribing.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forPatient($query->patientId, $query->status, $inScopeFacilityIds);
    }
}
