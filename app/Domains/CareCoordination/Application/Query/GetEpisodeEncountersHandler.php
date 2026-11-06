<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeEncounterSummary;
use App\Domains\CareCoordination\Domain\CareEpisodeReader;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetEpisodeEncountersHandler
{
    public function __construct(
        private readonly CareEpisodeReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<CareEpisodeEncounterSummary>
     */
    public function __invoke(GetEpisodeEncounters $query): array
    {
        $summary = $this->reader->summary($query->careEpisodeId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetEpisodeEncounters dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'care_coordination.access')) {
            throw NotFound::aggregate(CareEpisode::class, $query->careEpisodeId);
        }

        return $summary->encounters;
    }
}
