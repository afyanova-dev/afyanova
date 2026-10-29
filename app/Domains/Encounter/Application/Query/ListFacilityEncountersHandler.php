<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates ListFacilityEncounters (IC §6.1 / §6.3; ADR-130; E.13).
 *
 * 1. Checks that an authenticated user is bound to the request context.
 * 2. Enforces facility-scoped authorization: the caller must hold
 *    `encounter.encounters.read` in the target facility's scope.
 *    Unauthorized callers receive AuthorizationException (HTTP 403 Forbidden).
 * 3. Invokes EncounterReader::forFacility without cross-domain joins.
 *    Legitimate zero-result reads return an empty array (HTTP 200 OK).
 */
final class ListFacilityEncountersHandler
{
    public function __construct(
        private readonly EncounterReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<EncounterSummary>
     */
    public function __invoke(ListFacilityEncounters $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('ListFacilityEncounters dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'encounter.encounters.read',
            $this->scope->effectivePermissions($user, $query->facilityId),
            true,
        )) {
            throw new AuthorizationException("User does not hold encounter.encounters.read at facility {$query->facilityId}");
        }

        return $this->reader->forFacility(
            facilityId: $query->facilityId,
            date: $query->date,
            statuses: $query->statuses,
            limit: $query->limit,
        );
    }
}
