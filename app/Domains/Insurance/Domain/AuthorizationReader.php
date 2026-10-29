<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\AuthorizationSummary;

/**
 * Read-side port for the authorization aggregate (IC §6.2; ADR-202 D-C).
 */
interface AuthorizationReader
{
    public function summary(string $authorizationId): AuthorizationSummary;

    /**
     * @return list<AuthorizationSummary> Active first, then newest.
     */
    public function forPatient(string $patientId): array;

    /**
     * @return list<AuthorizationSummary> Pending (REQUESTED) worklist.
     */
    public function pending(): array;

    /**
     * Finds the current effective authorization covering the date for the patient and service category.
     */
    public function findEffective(string $patientId, string $serviceCategory, ?string $onDate = null): ?AuthorizationSummary;
}
