<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Domain\LabPanelReader;

/**
 * Orchestrates `GetLabPanels` (ADR-162 D-G).
 */
final class GetLabPanelsHandler
{
    public function __construct(private readonly LabPanelReader $reader) {}

    /**
     * @return list<LabPanelSummary>
     */
    public function __invoke(GetLabPanels $query): array
    {
        return $this->reader->list($query->active);
    }
}
