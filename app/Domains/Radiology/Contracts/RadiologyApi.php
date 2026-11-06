<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;

interface RadiologyApi
{
    /**
     * @return list<ImagingOrderSummary>
     */
    public function getPatientImagingOrders(string $patientId): array;
}
