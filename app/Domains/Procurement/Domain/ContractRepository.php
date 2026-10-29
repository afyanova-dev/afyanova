<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\Contract;
use App\Shared\Domain\Repository;

/** Write-side port for the Contract aggregate (IC §9; ADR-176 D-B, ADR-177 D-C). */
interface ContractRepository extends Repository
{
    public function getById(string $id): Contract;

    public function getByIdForUpdate(string $id): Contract;

    /**
     * Σ `ordered_base_qty` (base units) over every NON-CANCELLED `pcr_po_lines`
     * row carrying this
     * `contract_line_id` (its PO not CANCELLED either) — the `committed`
     * projection + the INV-33a cumulative-ceiling denominator (ADR-177 D-C /
     * D-D). Returns a scale-4 numeric string; '0.0000' when nothing consumes the
     * line. Callers that ENFORCE the ceiling must hold the `pcr_contracts` row
     * lock and have already written the candidate PO line(s) in the same txn.
     */
    public function committedQuantityForContractLine(string $contractLineId): string;

    /**
     * Σ `received_qty` over the same rows (ADR-177 D-C). Informational — it never
     * gates. Scale-4 numeric string.
     */
    public function receivedQuantityForContractLine(string $contractLineId): string;
}
