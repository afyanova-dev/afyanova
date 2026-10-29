<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Exception\ContractCeilingExceeded;
use App\Domains\Procurement\Domain\Exception\ContractPriceIsAuthoritative;
use App\Domains\Procurement\Domain\Exception\PurchaseOrderAmendmentInvalid;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `AmendPurchaseOrder` (ADR-171 D-H / D-I).
 *
 * Legal ONLY on an ISSUED PO (`PurchaseOrderLifecycle::assertCanAmend`). One
 * structured field changes (line `ordered_qty` / `unit_price` / `required_date`
 * / `delivery_location_ref`, or header `notes`); `pcr_po_amendments` gets one
 * append-only row (sequence = `amendment_count` + 1); `amendment_count` bumps so
 * the PO row is always dirty (T.2 lesson) and `version` advances;
 * `PurchaseOrderAmended` is emitted. NO reapproval (F3d). All in the bus's one
 * transaction — the `ReplaceTeamMemberHandler` plain-child-mutation precedent.
 *
 * When the amended line is contract-linked (`contract_line_id` set — Proc.3b,
 * ADR-177): a `unit_price` change must match the contract line's
 * `agreed_unit_price` (INV-price, D-E); an `ordered_qty` change re-asserts the
 * CUMULATIVE ceiling (INV-33a, D-D) — the whole post-write Σ for the contract
 * line, recomputed under the `pcr_contracts` row lock, ≤ `quantity_ceiling`.
 *
 * `PurchaseOrderRepository` stays the first repository parameter — the bus
 * resolves `PurchaseOrderPolicy` unchanged; `ContractRepository` is a later
 * parameter.
 */
final class AmendPurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
        private readonly ContractRepository $contracts,
    ) {}

    public function __invoke(AmendPurchaseOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->purchaseOrderId);

        $this->lifecycle->assertCanAmend((string) $order->status);

        $sequence = (int) $order->amendment_count + 1;
        $field = $command->targetField();
        $newValue = $this->blankToNull($command->newValue);

        if ($command->isLineLevel()) {
            [$previousValue, $lineId] = $this->applyToLine($order, $command, $field, $newValue);
        } else {
            $previousValue = $order->notes;
            $order->notes = $newValue;
            $lineId = null;
        }

        $order->recordAmendment($command->amendmentType, $sequence, $command->actorId);

        $this->orders->save($order);

        PoAmendment::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $order->tenant_id,
            'facility_id' => (string) $order->facility_id,
            'po_id' => (string) $order->id,
            'sequence' => $sequence,
            'amendment_type' => $command->amendmentType,
            'po_line_id' => $lineId,
            'field_changed' => $field,
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'reason' => trim($command->reason),
            'amended_by_user_id' => $command->actorId,
            'amended_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array{0: string|null, 1: string} [previousValue, lineId]
     */
    private function applyToLine(PurchaseOrder $order, AmendPurchaseOrder $command, string $field, ?string $newValue): array
    {
        /** @var PoLine|null $line */
        $line = PoLine::query()
            ->where('po_id', $order->id)
            ->whereKey($command->poLineId)
            ->first();

        if ($line === null) {
            throw PurchaseOrderAmendmentInvalid::unknownLine((string) $order->id, (string) $command->poLineId);
        }

        if ((string) $line->status === PoLine::STATUS_CANCELLED) {
            throw PurchaseOrderAmendmentInvalid::lineCancelled((string) $line->id);
        }

        // ADR-177 — a contract-linked line: load its contract line for the
        // INV-price check (D-E) and, for a quantity change, take the
        // `pcr_contracts` row lock BEFORE mutating (the INV-33a concurrency
        // boundary, D-D). NULL for every non-contract line.
        $contractLine = null;
        if ($line->contract_line_id !== null && ($field === 'ordered_qty' || $field === 'unit_price')) {
            /** @var ContractLine|null $contractLine */
            $contractLine = ContractLine::query()->whereKey($line->contract_line_id)->first();
            if ($contractLine !== null && $field === 'ordered_qty') {
                $this->contracts->getByIdForUpdate((string) $contractLine->contract_id);
            }
        }

        if ($field === 'unit_price' && $contractLine !== null
            && Money::of((string) $newValue) !== Money::of((string) $contractLine->agreed_unit_price)) {
            throw ContractPriceIsAuthoritative::forPoLine(
                (string) $line->id,
                Money::of((string) $newValue),
                Money::of((string) $contractLine->agreed_unit_price),
            );
        }

        if ($field === 'ordered_qty') {
            $previous = (string) $line->ordered_qty;
            $line->ordered_qty = (string) $newValue; // validated positive-numeric by the command ctor
        } elseif ($field === 'unit_price') {
            $previous = (string) $line->unit_price;
            $line->unit_price = Money::of((string) $newValue);
        } elseif ($field === 'required_date') {
            $previous = $line->required_date?->toDateString();
            $line->required_date = $newValue !== null ? CarbonImmutable::parse($newValue) : null;
        } else {
            $previous = $line->delivery_location_ref !== null ? (string) $line->delivery_location_ref : null;
            $line->delivery_location_ref = $newValue;
        }

        $line->save();

        // ADR-177 INV-33a — the candidate qty is written; recompute the whole Σ
        // for the contract line and roll the transaction back if it now exceeds.
        if ($field === 'ordered_qty' && $contractLine !== null && $contractLine->quantity_ceiling !== null) {
            $committed = $this->contracts->committedQuantityForContractLine((string) $line->contract_line_id);
            if (! Quantity::gte((string) $contractLine->quantity_ceiling, $committed)) {
                throw ContractCeilingExceeded::forContractLine(
                    (string) $line->contract_line_id,
                    $committed,
                    (string) $contractLine->quantity_ceiling,
                );
            }
        }

        return [$previous, (string) $line->id];
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
