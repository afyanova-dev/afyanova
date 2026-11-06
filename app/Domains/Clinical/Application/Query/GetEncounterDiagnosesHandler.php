<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterDiagnoses`. No transaction, audit, event, or state
 * mutation.
 *
 * @return list<DiagnosisSummary>
 */
final class GetEncounterDiagnosesHandler
{
    public function __construct(
        private readonly DiagnosisReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<DiagnosisSummary>
     */
    public function __invoke(GetEncounterDiagnoses $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterDiagnoses dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.diagnoses.read',
        );

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds);
    }
}
