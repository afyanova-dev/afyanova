<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetPatientProcedures` (IC §6.1 / §6.3; ADR-146 D-Q). No
 * transaction, no audit, no event, no state mutation. The `GetPatientProblems`
 * originating-facility-refinement shape.
 *
 * @return list<ProcedureSummary>
 */
final class GetPatientProceduresHandler
{
    public function __construct(
        private readonly ClinicalProcedureReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ProcedureSummary>
     */
    public function __invoke(GetPatientProcedures $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientProcedures dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.procedures.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forPatient($query->patientId, $inScopeFacilityIds);
    }
}
