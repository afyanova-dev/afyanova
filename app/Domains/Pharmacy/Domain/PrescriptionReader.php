<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;

/**
 * Read-side port for the Prescription aggregate (IC §6.2 / §6.3; ADR-181 D-F).
 * Returns DTOs only, never an Eloquent model.
 */
interface PrescriptionReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the current tenant scope (RLS). */
    public function summary(string $prescriptionId): PrescriptionSummary;

    /**
     * One patient's prescriptions, newest first; optional status filter.
     *
     * @return list<PrescriptionSummary>
     */
    public function forPatient(string $patientId, ?string $status): array;

    /**
     * The prescription queue at one facility; optional status filter.
     *
     * @return list<PrescriptionSummary>
     */
    public function forFacility(string $facilityId, ?string $status): array;
}
