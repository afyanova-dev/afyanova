<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\ContractAmendmentSummary;
use App\Domains\Procurement\Contracts\Dto\ContractLineSummary;
use App\Domains\Procurement\Contracts\Dto\ContractSummary;
use App\Domains\Procurement\Domain\ContractReader;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractAmendment;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Quantity;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;

final class EloquentContractReader implements ContractReader
{
    public function __construct(private readonly ContractRepository $contracts) {}

    public function summary(string $contractId): ContractSummary
    {
        $contract = Contract::query()->whereKey($contractId)->first()
            ?? throw NotFound::aggregate(Contract::class, $contractId);

        return $this->toSummary($contract);
    }

    public function search(?string $supplierId = null, ?string $status = null): array
    {
        $query = Contract::query();

        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (Contract $c): ContractSummary => $this->toSummary($c))
                ->all()
        );
    }

    private function toSummary(Contract $contract): ContractSummary
    {
        $lines = ContractLine::query()
            ->where('contract_id', $contract->id)
            ->orderBy('line_no')
            ->get()
            ->map(function (ContractLine $line): ContractLineSummary {
                $committed = $this->contracts->committedQuantityForContractLine((string) $line->id);
                $ceiling = $line->quantity_ceiling !== null ? (string) $line->quantity_ceiling : null;

                return new ContractLineSummary(
                    lineId: (string) $line->id,
                    lineNo: (int) $line->line_no,
                    lineType: (string) $line->line_type,
                    itemRef: $line->item_ref !== null ? (string) $line->item_ref : null,
                    itemCode: $line->item_code,
                    itemName: $line->item_name,
                    description: $line->description,
                    agreedUnitPrice: (string) $line->agreed_unit_price,
                    quantityCeiling: $ceiling,
                    uom: (string) $line->uom,
                    committed: $committed,
                    received: $this->contracts->receivedQuantityForContractLine((string) $line->id),
                    remaining: $ceiling !== null ? Quantity::subtract($ceiling, $committed) : null,
                );
            })
            ->all();

        $poCount = PurchaseOrder::query()->where('contract_id', $contract->id)->count();

        $amendments = ContractAmendment::query()
            ->where('contract_id', $contract->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (ContractAmendment $a): ContractAmendmentSummary => new ContractAmendmentSummary(
                sequence: (int) $a->sequence,
                amendmentType: (string) $a->amendment_type,
                contractLineId: $a->contract_line_id !== null ? (string) $a->contract_line_id : null,
                fieldChanged: (string) $a->field_changed,
                previousValue: $a->previous_value,
                newValue: $a->new_value,
                reason: (string) $a->reason,
                amendedByUserId: (string) $a->amended_by_user_id,
                amendedAt: $a->amended_at->toIso8601String(),
            ))
            ->all();

        $supersededByContractId = Contract::query()
            ->where('supersedes_contract_id', $contract->id)
            ->value('id');

        return new ContractSummary(
            contractId: (string) $contract->id,
            contractNumber: (string) $contract->contract_number,
            supplierId: (string) $contract->supplier_id,
            title: (string) $contract->title,
            reason: $contract->reason,
            effectiveDate: $contract->effective_date->toDateString(),
            expiryDate: $contract->expiry_date->toDateString(),
            terms: $contract->terms,
            currency: (string) $contract->currency,
            status: (string) $contract->status,
            isExpired: $contract->expiry_date->toDateString() < CarbonImmutable::now()->toDateString(),
            terminationReason: $contract->termination_reason,
            supersedesContractId: $contract->supersedes_contract_id !== null ? (string) $contract->supersedes_contract_id : null,
            supersededByContractId: $supersededByContractId !== null ? (string) $supersededByContractId : null,
            amendmentCount: (int) $contract->amendment_count,
            activatedByUserId: $contract->activated_by_user_id !== null ? (string) $contract->activated_by_user_id : null,
            activatedAt: $contract->activated_at?->toIso8601String(),
            createdByUserId: (string) $contract->created_by_user_id,
            poCount: $poCount,
            lines: array_values($lines),
            amendments: array_values($amendments),
            createdAt: $contract->created_at?->toIso8601String(),
        );
    }
}
