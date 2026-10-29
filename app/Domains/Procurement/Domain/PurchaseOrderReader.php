<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\ItemPriceReference;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLinePage;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderSearchPage;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;

/** Read-side port for purchase orders (IC §6.2 / §6.3; ADR-171 D-J). */
interface PurchaseOrderReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $purchaseOrderId): PurchaseOrderSummary;

    public function findSummaryByNumber(string $facilityId, string $poNumber): ?PurchaseOrderSummary;

    /**
     * POs at a facility, optionally filtered by status.
     *
     * @return list<PurchaseOrderSummary>
     */
    public function searchForFacility(string $facilityId, ?string $status = null): array;

    /**
     * POs created from requisitions in the supplied departments.
     *
     * @param  list<string>  $departmentRefs
     * @return list<PurchaseOrderSummary>
     */
    public function searchForFacilityAndDepartments(
        string $facilityId,
        array $departmentRefs,
        ?string $status = null,
    ): array;

    /**
     * The open (awaiting-delivery) POs at a facility — ISSUED | PARTIALLY_RECEIVED
     * (§86 / §307; widened by ADR-172 D-K).
     *
     * @return list<PurchaseOrderSummary>
     */
    public function openForFacility(string $facilityId): array;

    /**
     * Open orders sourced from requisitions in the supplied departments.
     *
     * @param  list<string>  $departmentRefs
     * @return list<PurchaseOrderSummary>
     */
    public function openForFacilityAndDepartments(string $facilityId, array $departmentRefs): array;

    /**
     * Search open POs for receiving without loading every line.
     *
     * @param  list<string>  $departmentRefs  empty = facility-wide
     */
    public function searchReceivable(
        string $facilityId,
        array $departmentRefs,
        string $query,
        int $page,
        int $perPage,
    ): OpenPurchaseOrderSearchPage;

    /**
     * One page of open STOCK lines for a selected PO.
     *
     * @param  list<string>  $departmentRefs  empty = facility-wide
     */
    public function receivableLines(
        string $facilityId,
        array $departmentRefs,
        string $purchaseOrderId,
        string $query,
        int $page,
        int $perPage,
    ): OpenPurchaseOrderLinePage;

    /**
     * P2-02 — price evidence for a supplier/item at a facility.
     *
     * @param  list<string>  $departmentRefs  empty = facility-wide
     */
    public function itemPriceReference(
        string $facilityId,
        array $departmentRefs,
        string $supplierId,
        string $itemId,
    ): ItemPriceReference;

    /**
     * The order total: the sum of the NON-CANCELLED line totals
     * (`ordered_qty * unit_price`), at money scale (`0.00` for a PO with no
     * lines). The Application-layer `PurchaseOrderSelfApprovalPolicy` is the
     * only consumer — the money that decision turns on.
     *
     * @return numeric-string
     */
    public function orderTotal(string $purchaseOrderId): string;
}
