<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientRegistration aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9). Implementation:
 * Infrastructure/Repository/EloquentPatientRegistrationRepository.
 */
interface PatientRegistrationRepository extends Repository {}
