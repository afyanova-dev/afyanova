<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\PrescriptionReader;

final class GetPatientPrescriptionsHandler
{
    public function __construct(private readonly PrescriptionReader $reader) {}

    /**
     * @return list<PrescriptionSummary>
     */
    public function __invoke(GetPatientPrescriptions $query): array
    {
        return $this->reader->forPatient(
            $query->patientId,
            $query->status !== null ? strtoupper(trim($query->status)) : null,
        );
    }
}
