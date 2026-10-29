<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabSpecimenSummary;

/**
 * Read-side port for LabSpecimen queries (ADR-158 D-G).
 */
interface LabSpecimenReader
{
    public function summary(string $specimenId): LabSpecimenSummary;

    /**
     * All specimens for an order, oldest first (§35 — the rejected-specimen
     * history must be preserved and visible).
     *
     * @return list<LabSpecimenSummary>
     */
    public function forOrder(string $orderId): array;
}
