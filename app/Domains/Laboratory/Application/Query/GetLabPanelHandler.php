<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Domain\LabPanelReader;

/**
 * Orchestrates `GetLabPanel` (ADR-162 D-G). The catalog is tenant-wide
 * reference data (RLS-scoped) — the `#[ProtectedQuery]` gate is the
 * authorization (the CP.1 `GetProcedureDefinition` precedent).
 */
final class GetLabPanelHandler
{
    public function __construct(private readonly LabPanelReader $reader) {}

    public function __invoke(GetLabPanel $query): LabPanelSummary
    {
        return $this->reader->summary($query->panelId);
    }
}
