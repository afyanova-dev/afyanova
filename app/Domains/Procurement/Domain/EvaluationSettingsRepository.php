<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the per-tenant `EvaluationSettings` aggregate (IC §9;
 * ADR-175 D-B). Used only by the `procurement:configure-evaluation` console.
 */
interface EvaluationSettingsRepository extends Repository
{
    public function getById(string $id): EvaluationSettings;

    public function getByIdForUpdate(string $id): EvaluationSettings;

    /** The tenant's settings row, or null (RLS-scoped — one row per tenant). */
    public function findForTenant(string $tenantId): ?EvaluationSettings;
}
