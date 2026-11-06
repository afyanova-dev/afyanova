<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application;

use App\Domains\CareCoordination\Application\Query\GetPatientCareEpisodes;
use App\Domains\CareCoordination\Application\Query\GetPatientReferrals;
use App\Domains\CareCoordination\Application\Query\SearchReferrals;
use App\Domains\CareCoordination\Contracts\CareCoordinationApi;
use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;
use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Shared\Bus\QueryBus;

final readonly class CareCoordinationApiService implements CareCoordinationApi
{
    public function __construct(private QueryBus $queryBus) {}

    /**
     * @return list<ReferralSummary>
     */
    public function searchReferrals(string $facilityId): array
    {
        /** @var list<ReferralSummary> */
        return $this->queryBus->dispatch(new SearchReferrals($facilityId));
    }

    /**
     * @return list<CareEpisodeSummary>
     */
    public function getPatientCareEpisodes(string $patientId): array
    {
        /** @var list<CareEpisodeSummary> */
        return $this->queryBus->dispatch(new GetPatientCareEpisodes($patientId));
    }

    /**
     * @return list<ReferralSummary>
     */
    public function getPatientReferrals(string $patientId): array
    {
        /** @var list<ReferralSummary> */
        return $this->queryBus->dispatch(new GetPatientReferrals($patientId));
    }
}
