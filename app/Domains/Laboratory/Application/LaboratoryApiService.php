<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application;

use App\Domains\Laboratory\Application\Query\GetLabPanels;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitions;
use App\Domains\Laboratory\Application\Query\GetOrderResults;
use App\Domains\Laboratory\Application\Query\GetPatientLabOrders;
use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;
use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Contracts\Dto\LabResultSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Contracts\LaboratoryApi;
use App\Shared\Bus\QueryBus;

final readonly class LaboratoryApiService implements LaboratoryApi
{
    public function __construct(private QueryBus $queryBus) {}

    /**
     * @return list<LabOrderSummary>
     */
    public function getPatientLabOrders(string $patientId): array
    {
        /** @var list<LabOrderSummary> */
        return $this->queryBus->dispatch(new GetPatientLabOrders($patientId));
    }

    /**
     * @return list<LabResultSummary>
     */
    public function getOrderResults(string $orderId): array
    {
        /** @var list<LabResultSummary> */
        return $this->queryBus->dispatch(new GetOrderResults($orderId));
    }

    /**
     * @return list<LabTestDefinitionSummary>
     */
    public function getLabTestDefinitions(?string $category = null, ?bool $active = null): array
    {
        /** @var list<LabTestDefinitionSummary> */
        return $this->queryBus->dispatch(new GetLabTestDefinitions($category, $active));
    }

    /**
     * @return list<LabPanelSummary>
     */
    public function getLabPanels(?bool $active = null): array
    {
        /** @var list<LabPanelSummary> */
        return $this->queryBus->dispatch(new GetLabPanels($active));
    }
}
