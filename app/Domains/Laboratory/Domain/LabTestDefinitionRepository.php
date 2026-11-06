<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the LabTestDefinition aggregate (IC §9; ADR-157 D-C).
 * Used by the catalog workspace and the `laboratory:define-test` operator command.
 */
interface LabTestDefinitionRepository extends Repository
{
    public function getById(string $id): LabTestDefinition;

    public function getByIdForUpdate(string $id): LabTestDefinition;

    /** The active definition with this `code`, or null (tenant-scoped, RLS). */
    public function findActiveByCode(string $tenantId, string $code): ?LabTestDefinition;
}
