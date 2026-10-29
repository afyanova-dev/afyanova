<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Shared\Domain\Repository;

/** Write-side port for the Quotation aggregate (IC §9; ADR-173 D-C). */
interface QuotationRepository extends Repository
{
    public function getById(string $id): Quotation;

    public function getByIdForUpdate(string $id): Quotation;

    /** The quotation for this (RFQ, supplier), or null (RLS-scoped). */
    public function findByRfqAndSupplier(string $rfqId, string $supplierId): ?Quotation;

    /**
     * Every quotation on the RFQ, locked `FOR UPDATE` — the `CloseRfq` /
     * `AwardRfq` consistency point.
     *
     * @return list<Quotation>
     */
    public function lockAllForRfq(string $rfqId): array;
}
