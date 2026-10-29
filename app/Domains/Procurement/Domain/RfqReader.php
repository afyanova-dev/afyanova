<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\RfqSummary;

/** Read-side port for RFQs (IC §6.2 / §6.3; ADR-173 D-F). Metadata + award outcome — NO competing prices. */
interface RfqReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $rfqId): RfqSummary;

    /** The RFQ's `facility_id` (RLS-scoped) — for the confidentiality-gated quotation reads' ADR-133 refinement. */
    public function facilityOf(string $rfqId): string;

    /**
     * RFQs at a facility, optionally filtered by status.
     *
     * @return list<RfqSummary>
     */
    public function searchForFacility(string $facilityId, ?string $status = null): array;
}
