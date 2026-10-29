<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Domain\PatientReader;

final class GetPatientSummaryHandler
{
    public function __construct(private readonly PatientReader $reader) {}

    public function __invoke(GetPatientSummary $query): PatientSummary
    {
        return $this->reader->summary($query->patientId);
    }
}
