<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the LabTestDefinition aggregate (IC §9; ADR-157 D-C).
 * Used ONLY by the `laboratory:define-test` console command — there is no HTTP
 * bus command for catalog authoring in Lab.1 (the CP.1 precedent).
 */
interface LabTestDefinitionRepository extends Repository
{
    public function getById(string $id): LabTestDefinition;

    public function getByIdForUpdate(string $id): LabTestDefinition;

    /** The active definition with this `code`, or null (tenant-scoped, RLS). */
    public function findActiveByCode(string $tenantId, string $code): ?LabTestDefinition;
}
