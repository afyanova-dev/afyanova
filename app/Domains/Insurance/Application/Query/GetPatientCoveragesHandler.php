<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Domain\MemberCoverageReader;

final class GetPatientCoveragesHandler
{
    public function __construct(private readonly MemberCoverageReader $reader) {}

    /**
     * @return list<MemberCoverageSummary>
     */
    public function __invoke(GetPatientCoverages $query): array
    {
        return $this->reader->forPatient($query->patientId);
    }
}
