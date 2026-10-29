<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Quantity;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentContractRepository extends EloquentRepository implements ContractRepository
{
    protected function model(): string
    {
        return Contract::class;
    }

    public function getById(string $id): Contract
    {
        return Contract::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Contract::class, $id);
    }

    public function getByIdForUpdate(string $id): Contract
    {
        return Contract::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Contract::class, $id);
    }

    public function committedQuantityForContractLine(string $contractLineId): string
    {
        // Base units: a contract line's ceiling is expressed in the contract's
        // unit, so a pack-entered PO line must commit its base quantity.
        return $this->sumForContractLine($contractLineId, 'ordered_base_qty');
    }

    public function receivedQuantityForContractLine(string $contractLineId): string
    {
        return $this->sumForContractLine($contractLineId, 'received_qty');
    }

    /**
     * Σ of one `pcr_po_lines` decimal column, restricted to the line's
     * `contract_line_id` and excluding CANCELLED lines AND lines of a CANCELLED
     * PO (ADR-177 D-D — "a cancelled PO commits nothing"). Both tables are
     * tenant-RLS'd, so the join is tenant-safe.
     */
    private function sumForContractLine(string $contractLineId, string $column): string
    {
        $sum = PoLine::query()
            ->join('pcr_purchase_orders', 'pcr_purchase_orders.id', '=', 'pcr_po_lines.po_id')
            ->where('pcr_po_lines.contract_line_id', $contractLineId)
            ->where('pcr_po_lines.status', '!=', PoLine::STATUS_CANCELLED)
            ->where('pcr_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->sum('pcr_po_lines.'.$column);

        return Quantity::of((string) $sum);
    }
}
