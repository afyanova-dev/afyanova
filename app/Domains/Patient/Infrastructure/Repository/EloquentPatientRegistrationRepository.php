<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Patient\Domain\PatientRegistrationRepository;
use App\Shared\Domain\EloquentRepository;

final class EloquentPatientRegistrationRepository extends EloquentRepository implements PatientRegistrationRepository
{
    protected function model(): string
    {
        return PatientRegistration::class;
    }
}
