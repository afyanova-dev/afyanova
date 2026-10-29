<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabOrderItemResultContext;
use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;

/**
 * Read-side port for LabOrder queries (ADR-157 D-G; ADR-159 D-G).
 */
interface LabOrderReader
{
    public function summary(string $orderId): LabOrderSummary;

    /**
     * The context `RecordResult` needs to bind a result to an order item
     * (ADR-159 D-F) — the item's order, the order's patient / facility, and the
     * catalog snapshots. Throws `App\Shared\Domain\NotFound` if the item is
     * absent from the current tenant scope.
     */
    public function itemResultContext(string $orderItemId): LabOrderItemResultContext;

    /**
     * Active (non-terminal) orders at a facility — the laboratory work queue
     * (SearchLabOrders — §71, §133).
     *
     * @return list<LabOrderSummary>
     */
    public function activeForFacility(string $facilityId): array;

    /**
     * All orders for a patient, tenant-wide (§201).
     *
     * @return list<LabOrderSummary>
     */
    public function forPatient(string $patientId): array;

    /** Count of non-CANCELLED items on an order (the SubmitLabOrder guard). */
    public function activeItemCount(string $orderId): int;
}
