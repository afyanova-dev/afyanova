<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Domain\PatientReader;

final class GetPatientSummariesHandler
{
    public function __construct(private readonly PatientReader $reader) {}

    /**
     * @return array<string, PatientSummary> Keyed by patientId
     */
    public function __invoke(GetPatientSummaries $query): array
    {
        if ($query->patientIds === []) {
            return [];
        }

        return $this->reader->summaries($query->patientIds);
    }
}
