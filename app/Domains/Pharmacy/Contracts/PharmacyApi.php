<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;

interface PharmacyApi
{
    /**
     * @return list<PrescriptionSummary>
     */
    public function getPatientPrescriptions(string $patientId): array;
}
