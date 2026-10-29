<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetPatientVitalSigns` (IC §6.1 / §6.3). No transaction — the
 * QueryBus runs inside the request tenant scope (IC §6.1). No audit, no event,
 * no state mutation.
 *
 * Flow (the `GetPatientProblems` precedent, ADR-133):
 *   1. Resolve the caller's in-scope facilities holding `clinical.vitals.read`.
 *   2. `VitalSignsObservationReader::forPatient()` — RLS-scoped, newest first.
 */
final class GetPatientVitalSignsHandler
{
    public function __construct(
        private readonly VitalSignsObservationReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<VitalSignsObservationSummary>
     */
    public function __invoke(GetPatientVitalSigns $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientVitalSigns dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.vitals.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forPatient($query->patientId, $inScopeFacilityIds);
    }
}
