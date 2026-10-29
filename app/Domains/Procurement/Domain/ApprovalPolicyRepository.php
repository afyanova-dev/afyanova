<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the per-facility / per-department `ApprovalPolicy`
 * aggregate (IC §9; Shape B). Used by `procurement:configure-approval-limit`
 * and read by the Application-layer `ApprovalLimitResolver`.
 */
interface ApprovalPolicyRepository extends Repository
{
    public function getById(string $id): ApprovalPolicy;

    public function getByIdForUpdate(string $id): ApprovalPolicy;

    /**
     * The row for an exact scope: `department_ref = NULL` is the facility-wide
     * limit. RLS-scoped; null when the scope has no row.
     */
    public function findFor(string $facilityId, ?string $departmentRef): ?ApprovalPolicy;

    /**
     * Every row at a facility — the facility-wide one first, then departments
     * by code-independent id. For the settings screen.
     *
     * @return list<ApprovalPolicy>
     */
    public function listForFacility(string $facilityId): array;

    /**
     * Remove a scope's row — the scope then falls back to the facility row /
     * facility-type default.
     */
    public function delete(ApprovalPolicy $policy): void;
}
