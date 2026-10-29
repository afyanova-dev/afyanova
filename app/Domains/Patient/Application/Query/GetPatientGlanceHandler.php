<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientGlance;
use App\Domains\Patient\Domain\PatientReader;

final class GetPatientGlanceHandler
{
    public function __construct(private readonly PatientReader $reader) {}

    public function __invoke(GetPatientGlance $query): PatientGlance
    {
        return $this->reader->glance($query->patientId, $query->facilityId);
    }
}
