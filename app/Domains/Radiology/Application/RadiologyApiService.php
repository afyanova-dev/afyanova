<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application;

use App\Domains\Radiology\Application\Query\GetPatientImagingOrders;
use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;
use App\Domains\Radiology\Contracts\RadiologyApi;
use App\Shared\Bus\QueryBus;

final readonly class RadiologyApiService implements RadiologyApi
{
    public function __construct(private QueryBus $queryBus) {}

    /**
     * @return list<ImagingOrderSummary>
     */
    public function getPatientImagingOrders(string $patientId): array
    {
        /** @var list<ImagingOrderSummary> */
        return $this->queryBus->dispatch(new GetPatientImagingOrders($patientId));
    }
}
