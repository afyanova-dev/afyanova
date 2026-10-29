<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the LabPanel aggregate (ADR-162 D-C). Used ONLY by the
 * `laboratory:define-panel` console command.
 */
interface LabPanelRepository extends Repository
{
    public function getById(string $id): LabPanel;

    public function getByIdForUpdate(string $id): LabPanel;

    public function findActiveByCode(string $tenantId, string $code): ?LabPanel;
}
