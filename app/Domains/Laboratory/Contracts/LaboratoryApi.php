<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts;

use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;
use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Contracts\Dto\LabResultSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;

interface LaboratoryApi
{
    /**
     * @return list<LabOrderSummary>
     */
    public function getPatientLabOrders(string $patientId): array;

    /**
     * @return list<LabResultSummary>
     */
    public function getOrderResults(string $orderId): array;

    /** @return list<LabTestDefinitionSummary> */
    public function getLabTestDefinitions(?string $category = null, ?bool $active = null): array;

    /** @return list<LabPanelSummary> */
    public function getLabPanels(?bool $active = null): array;
}
