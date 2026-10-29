<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for ProfessionalRegistration aggregate root (25-hr-and-workforce-domain.md §3; ADR-206).
 */
interface ProfessionalRegistrationRepository extends Repository
{
    public function getById(string $id): ProfessionalRegistration;

    public function getByIdForUpdate(string $id): ProfessionalRegistration;

    public function existsByLicence(string $council, string $licenceNumber, ?string $excludeId = null): bool;

    public function existsById(string $id): bool;
}
