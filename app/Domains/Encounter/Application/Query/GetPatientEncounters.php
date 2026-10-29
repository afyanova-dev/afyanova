<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * List a patient's encounters at a facility, most recent first.
 * Backs the patient chart Visits section.
 *
 * Protected (IC §6.2): encounter metadata is facility-scoped (§114, Invariant 24/25).
 * The `#[ProtectedQuery]` gate enforces coarse tenant + `encounter.encounters.read`
 * (`EncounterPolicy::view`). `GetPatientEncountersHandler` then enforces that the
 * authenticated caller holds `encounter.encounters.read` specifically in the target
 * facility's scope.
 *
 * Unauthorized callers are rejected with AuthorizationException (HTTP 403 Forbidden).
 * Zero results for an authorized query return an empty collection (HTTP 200 OK).
 */
#[ProtectedQuery(ability: 'view', subjectType: Encounter::class)]
final readonly class GetPatientEncounters
{
    /**
     * @param  list<EncounterStatus|string>|null  $statuses
     */
    public function __construct(
        public string $patientId,
        public string $facilityId,
        public ?array $statuses = null,
        public int $limit = 100,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientEncounters::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPatientEncounters::$facilityId must be a UUID (IC §3).');
        }

        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('GetPatientEncounters::$limit must be between 1 and 500.');
        }
    }
}
