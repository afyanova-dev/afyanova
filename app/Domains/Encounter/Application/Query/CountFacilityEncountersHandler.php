<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Domain\EncounterReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class CountFacilityEncountersHandler
{
    public function __construct(
        private readonly EncounterReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(CountFacilityEncounters $query): int
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('CountFacilityEncounters dispatched without an authenticated user.');
        }

        if (! in_array(
            'encounter.encounters.read',
            $this->scope->effectivePermissions($user, $query->facilityId),
            true,
        )) {
            throw new AuthorizationException("User does not hold encounter.encounters.read at facility {$query->facilityId}");
        }

        return $this->reader->countForFacility(
            facilityId: $query->facilityId,
            date: $query->date,
            statuses: $query->statuses,
        );
    }
}
