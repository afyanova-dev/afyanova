<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\ProfessionalRegistrationSummary;

/**
 * Read-side port for ProfessionalRegistration queries (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
interface ProfessionalRegistrationReader
{
    public function summary(string $registrationId): ProfessionalRegistrationSummary;

    /**
     * @return list<ProfessionalRegistrationSummary>
     */
    public function forStaff(string $staffId): array;

    /**
     * @return list<ProfessionalRegistrationSummary>
     */
    public function watchlist(): array;
}
