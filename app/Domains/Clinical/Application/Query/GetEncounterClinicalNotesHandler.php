<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\ClinicalNoteReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterClinicalNotes`. No transaction, audit, event, or
 * state mutation.
 *
 * @return list<ClinicalNoteSummary>
 */
final class GetEncounterClinicalNotesHandler
{
    public function __construct(
        private readonly ClinicalNoteReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<ClinicalNoteSummary>
     */
    public function __invoke(GetEncounterClinicalNotes $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterClinicalNotes dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.notes.read',
        );

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds);
    }
}
