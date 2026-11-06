<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;
use App\Domains\CareCoordination\Domain\CareEpisodeReader;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetCareEpisodeHandler
{
    public function __construct(
        private readonly CareEpisodeReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetCareEpisode $query): CareEpisodeSummary
    {
        $summary = $this->reader->summary($query->careEpisodeId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetCareEpisode dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'care_coordination.access')) {
            throw NotFound::aggregate(CareEpisode::class, $query->careEpisodeId);
        }

        return $summary;
    }
}
