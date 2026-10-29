<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Patient\Domain\PatientReader;

final class GetPatientProfileHandler
{
    public function __construct(private readonly PatientReader $reader) {}

    public function __invoke(GetPatientProfile $query): PatientProfileData
    {
        return $this->reader->profile($query->patientId, $query->facilityId);
    }
}
