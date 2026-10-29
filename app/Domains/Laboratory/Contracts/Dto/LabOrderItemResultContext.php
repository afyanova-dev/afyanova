<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * The minimal context `RecordResult` needs to bind a result to an order item
 * (ADR-159 D-F): the item's order, the order's patient / facility, and the
 * catalog snapshots to copy onto the result.
 */
final readonly class LabOrderItemResultContext
{
    public function __construct(
        public string $orderItemId,
        public string $orderId,
        public string $patientId,
        public string $facilityId,
        public string $testDefinitionId,
        public string $testCode,
        public string $testName,
    ) {}
}
