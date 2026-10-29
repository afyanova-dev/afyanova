<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ProcedureDefinition aggregate (IC §9; ADR-146 D-C).
 * Implementation: `Infrastructure/Repository/EloquentProcedureDefinitionRepository`,
 * bound in `ClinicalServiceProvider`. Used ONLY by the
 * `clinical:define-procedure` console command and the catalog seeder — there is
 * no HTTP bus command for catalog authoring in CP.1.
 */
interface ProcedureDefinitionRepository extends Repository
{
    public function getById(string $id): ProcedureDefinition;

    public function getByIdForUpdate(string $id): ProcedureDefinition;

    /** The definition with this `code`, or null (tenant-scoped, RLS). */
    public function findByCode(string $tenantId, string $code): ?ProcedureDefinition;
}
