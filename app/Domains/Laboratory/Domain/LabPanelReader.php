<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;

/**
 * Read-side port for LabPanel (ADR-162 D-G). Also the INTRA-domain read
 * `CreateLabOrderHandler` uses to expand a panel to its member tests.
 */
interface LabPanelReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the panel is absent from the
     * current tenant scope.
     */
    public function summary(string $panelId): LabPanelSummary;

    /**
     * The tenant's panel catalog, optionally filtered by active.
     *
     * @return list<LabPanelSummary>
     */
    public function list(?bool $active = null): array;
}
