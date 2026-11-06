<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application;

use App\Domains\Pharmacy\Application\Query\GetPatientPrescriptions;
use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Contracts\PharmacyApi;
use App\Shared\Bus\QueryBus;

final readonly class PharmacyApiService implements PharmacyApi
{
    public function __construct(private QueryBus $queryBus) {}

    /**
     * @return list<PrescriptionSummary>
     */
    public function getPatientPrescriptions(string $patientId): array
    {
        /** @var list<PrescriptionSummary> */
        return $this->queryBus->dispatch(new GetPatientPrescriptions($patientId));
    }
}
