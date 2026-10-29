<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;
use App\Domains\CareCoordination\Domain\CareEpisodeReader;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientCareEpisodesHandler
{
    public function __construct(
        private readonly CareEpisodeReader $reader,
    ) {}

    /**
     * @return list<CareEpisodeSummary>
     */
    public function __invoke(GetPatientCareEpisodes $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientCareEpisodes dispatched without an authenticated user (IC §6.1).');
        }

        if (! $user->hasPermissionKey('care_coordination.access')) {
            throw NotFound::aggregate(CareEpisode::class, $query->patientId);
        }

        return $this->reader->forPatient($query->patientId);
    }
}
