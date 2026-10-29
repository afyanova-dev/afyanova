<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;

/** Read-side port for purchase requisitions (IC §6.2 / §6.3; ADR-171 D-J). */
interface PurchaseRequisitionReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $requisitionId): PurchaseRequisitionSummary;

    /**
     * Requisitions at a facility, optionally filtered by status.
     *
     * @return list<PurchaseRequisitionSummary>
     */
    public function searchForFacility(string $facilityId, ?string $status = null): array;

    /**
     * The SUBMITTED requisitions at a facility — the approval queue (§307).
     *
     * @return list<PurchaseRequisitionSummary>
     */
    public function approvalQueue(string $facilityId): array;
}
