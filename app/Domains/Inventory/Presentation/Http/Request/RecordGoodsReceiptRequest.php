<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Application\Command\RecordGoodsReceipt;
use App\Domains\Inventory\Application\Query\GetItems;
use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLineRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderRef;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the Phase 1 receiving workbench form.
 *
 * The request is deliberately stricter than the Inventory domain: this is the
 * receiving surface. It proves that every PROCUREMENT line is an OPEN line of
 * an open PO at the active facility, that the item matches that exact line, and
 * that the aggregate delivered quantity does not exceed the line's remaining
 * quantity. The handler still owns stock truth.
 */
final class RecordGoodsReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            foreach ($lines as $index => $line) {
                if (is_array($line) && isset($line['entered_unit']) && is_string($line['entered_unit'])) {
                    $lines[$index]['entered_unit'] = strtoupper(trim($line['entered_unit']));
                }
            }

            $this->merge(['lines' => $lines]);
        }

        if (is_string($this->input('invoice_currency'))) {
            $this->merge(['invoice_currency' => strtoupper(trim((string) $this->input('invoice_currency')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.receipts.create') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'receipt_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'source_type' => ['required', 'in:DONATION,OPENING_BALANCE,OTHER,PROCUREMENT'],
            'po_ref' => ['nullable', 'string', 'max:120', 'prohibited_unless:source_type,PROCUREMENT'],
            'supplier_ref' => ['nullable', 'uuid'],
            'delivery_note_number' => ['nullable', 'string', 'max:120'],
            'delivery_note_missing_reason' => ['nullable', 'string', 'max:500'],
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_status' => ['required', 'in:RECEIVED,PENDING,NOT_REQUIRED'],
            'invoice_currency' => ['required', 'string', 'size:3'],
            'invoice_total' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1', 'max:'.RecordGoodsReceipt::MAX_LINES],
            'lines.*.receipt_line_id' => ['required', 'uuid'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.po_line_id' => [
                'nullable',
                'uuid',
                'required_if:source_type,PROCUREMENT',
                'prohibited_unless:source_type,PROCUREMENT',
            ],
            'lines.*.entered_unit' => ['required', 'string', 'max:20'],
            'lines.*.entered_quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'lines.*.pack_size' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'lines.*.quantity_delivered' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'lines.*.quantity_rejected' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:120'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.manufacture_date' => ['nullable', 'date'],
            'lines.*.condition' => ['required', 'in:GOOD,DAMAGED,SHORT,NEAR_EXPIRY,WRONG_ITEM'],
            'lines.*.condition_notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.substituted_for_item_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'po_ref.prohibited_unless' => 'A purchase order can only be referenced when the receipt source is a purchase order.',
            'lines.max' => 'A goods receipt can contain at most 500 lines.',
            'lines.*.po_line_id.required_if' => 'Select the purchase order line this receipt line is against.',
            'lines.*.po_line_id.prohibited_unless' => 'A purchase order line can only be referenced when the receipt source is a purchase order.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('lines')) {
                    return;
                }

                $items = $this->activeItems();
                $lines = $this->input('lines');
                $lines = is_array($lines) ? array_values($lines) : [];

                foreach ($lines as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $this->validateLine($validator, $index, $line, $items);
                }

                $this->validateProcurement($validator, $lines);
                $this->validateBackdateReason($validator);
            },
        ];
    }

    /**
     * @return array<string, ItemSummary>
     */
    private function activeItems(): array
    {
        $items = app(QueryBus::class)->dispatch(new GetItems(active: true));
        $byId = [];

        foreach ($items as $item) {
            if ($item instanceof ItemSummary) {
                $byId[$item->itemId] = $item;
            }
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, ItemSummary>  $items
     */
    private function validateLine(Validator $validator, int $index, array $line, array $items): void
    {
        $itemId = $line['item_id'] ?? null;
        $item = is_string($itemId) ? ($items[$itemId] ?? null) : null;

        if ($item === null) {
            $validator->errors()->add("lines.{$index}.item_id", 'Select an active stock item.');

            return;
        }

        if ($item->trackSerial) {
            $validator->errors()->add(
                "lines.{$index}.item_id",
                'Serial-tracked items cannot be received through this workflow yet.',
            );
        }

        $batchNumber = $line['batch_number'] ?? null;
        if ($item->trackBatch && (! is_string($batchNumber) || trim($batchNumber) === '')) {
            $validator->errors()->add("lines.{$index}.batch_number", 'A batch number is required for this item.');
        }

        $expiryDate = $line['expiry_date'] ?? null;
        if ($item->trackBatch && $item->trackExpiry && (! is_string($expiryDate) || trim($expiryDate) === '')) {
            $validator->errors()->add("lines.{$index}.expiry_date", 'An expiry date is required for this item.');
        }

        if ($item->trackExpiry && is_string($expiryDate) && trim($expiryDate) !== '') {
            $effective = $this->effectiveReceivedAt();

            if (CarbonImmutable::parse($expiryDate)->startOfDay()->lessThan($effective->startOfDay())) {
                $validator->errors()->add("lines.{$index}.expiry_date", 'This batch is already expired as of the receipt date.');
            }
        }

        $rejected = $line['quantity_rejected'] ?? '0';
        $delivered = $line['quantity_delivered'] ?? null;

        if (is_numeric($rejected) && is_numeric($delivered)
            && Quantity::compare((string) $rejected, (string) $delivered) > 0) {
            $validator->errors()->add("lines.{$index}.quantity_rejected", 'Rejected quantity cannot exceed delivered quantity.');
        }

        if (is_numeric($rejected) && Quantity::isPositive((string) $rejected)
            && (! isset($line['rejection_reason']) || ! is_string($line['rejection_reason']) || trim($line['rejection_reason']) === '')) {
            $validator->errors()->add("lines.{$index}.rejection_reason", 'Give a reason for the rejected quantity.');
        }

        $condition = is_string($line['condition'] ?? null) ? strtoupper(trim((string) $line['condition'])) : '';
        $substitutedFor = $line['substituted_for_item_id'] ?? null;
        $hasSubstitution = is_string($substitutedFor) && trim($substitutedFor) !== '';

        if ($hasSubstitution && $condition !== 'WRONG_ITEM') {
            $validator->errors()->add(
                "lines.{$index}.condition",
                'A substituted item must be recorded with the WRONG_ITEM condition.',
            );
        }

        if ($condition === 'WRONG_ITEM' && is_numeric($delivered) && is_numeric($rejected)
            && Quantity::isPositive(Quantity::sub((string) $delivered, (string) $rejected)) && ! $hasSubstitution) {
            $validator->errors()->add(
                "lines.{$index}.substituted_for_item_id",
                'Choose the substitute item that actually arrived, or reject the line.',
            );
        }

        $this->validatePackEntry($validator, $index, $line, $item);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function validatePackEntry(Validator $validator, int $index, array $line, ItemSummary $item): void
    {
        $enteredUnit = is_string($line['entered_unit'] ?? null) ? strtoupper(trim((string) $line['entered_unit'])) : '';
        $enteredQuantity = is_numeric($line['entered_quantity'] ?? null) ? Quantity::of((string) $line['entered_quantity']) : null;
        $packSize = is_numeric($line['pack_size'] ?? null) ? Quantity::of((string) $line['pack_size']) : null;
        $delivered = is_numeric($line['quantity_delivered'] ?? null) ? Quantity::of((string) $line['quantity_delivered']) : null;

        if ($enteredUnit === '' || $enteredQuantity === null || $packSize === null || $delivered === null) {
            return;
        }

        $baseUom = strtoupper((string) $item->uom);
        $itemPackUnit = $item->packUnit !== null ? strtoupper((string) $item->packUnit) : null;

        if ($enteredUnit === $baseUom) {
            if (Quantity::compare($packSize, '1') !== 0) {
                $validator->errors()->add("lines.{$index}.pack_size", 'A base-unit line has a pack size of 1.');

                return;
            }
        } elseif ($itemPackUnit === null || $enteredUnit !== $itemPackUnit) {
            $validator->errors()->add("lines.{$index}.entered_unit", $itemPackUnit === null
                ? sprintf('This item is stocked in %s and has no buying unit.', $baseUom)
                : sprintf('This item is stocked in %s and bought in %s.', $baseUom, $itemPackUnit));

            return;
        }

        if (Quantity::compare(Quantity::mul($enteredQuantity, $packSize), $delivered) !== 0) {
            $validator->errors()->add(
                "lines.{$index}.quantity_delivered",
                'The entered quantity and pack size do not multiply to the delivered stock quantity.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function validateProcurement(Validator $validator, array $lines): void
    {
        if ($this->input('source_type') !== 'PROCUREMENT') {
            foreach ($lines as $index => $line) {
                if (isset($line['po_line_id']) && $line['po_line_id'] !== '') {
                    $validator->errors()->add(
                        "lines.{$index}.po_line_id",
                        'A purchase order line can only be referenced when the receipt source is a purchase order.',
                    );
                }
            }

            return;
        }

        if ($validator->errors()->has('po_ref') || $validator->errors()->has('source_type')) {
            return;
        }

        $poRef = $this->input('po_ref');
        if (! is_string($poRef) || trim($poRef) === '') {
            $validator->errors()->add('po_ref', 'Select the purchase order this receipt is against.');

            return;
        }

        $facilityId = app(TenantContext::class)->facilityId();
        if ($facilityId === null || $facilityId === '') {
            return;
        }

        $procurement = app(ProcurementApi::class);

        try {
            $search = $procurement->searchOpenPurchaseOrders($facilityId, trim($poRef), 1, 5);
        } catch (NotFound|AuthorizationException) {
            $validator->errors()->add('po_ref', 'You are not authorised to receive against purchase orders.');

            return;
        }

        $purchaseOrder = collect($search->orders)->first(
            fn (OpenPurchaseOrderRef $po): bool => $po->poNumber === trim($poRef),
        );

        if ($purchaseOrder === null) {
            $validator->errors()->add('po_ref', 'That purchase order is not open at this facility.');

            return;
        }

        try {
            $openLines = $this->allOpenLines($procurement, $facilityId, $purchaseOrder->purchaseOrderId);
        } catch (NotFound|AuthorizationException) {
            $validator->errors()->add('po_ref', 'You are not authorised to receive against purchase orders.');

            return;
        }

        $byId = [];
        foreach ($openLines as $openLine) {
            $byId[$openLine->lineId] = $openLine;
        }

        $deliveredByLine = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $poLineId = $line['po_line_id'] ?? null;

            if (! is_string($poLineId) || trim($poLineId) === '') {
                $validator->errors()->add("lines.{$index}.po_line_id", 'Select the purchase order line this receipt line is against.');

                continue;
            }

            if (isset($seen[$poLineId])) {
                $validator->errors()->add("lines.{$index}.po_line_id", 'This purchase-order line appears more than once. Combine it into one receipt line.');
            }
            $seen[$poLineId] = true;

            $openLine = $byId[$poLineId] ?? null;

            if ($openLine === null) {
                $validator->errors()->add("lines.{$index}.po_line_id", 'That line is not open on the selected purchase order.');

                continue;
            }

            $itemId = $line['item_id'] ?? null;
            $substitutedFor = $line['substituted_for_item_id'] ?? null;

            if (is_string($substitutedFor) && trim($substitutedFor) !== '') {
                // P1-11 — a substitution must name the PO line's original item,
                // not an arbitrary third item. The substitute posts to stock,
                // while the PO line still receives the accepted quantity.
                if ($substitutedFor !== $openLine->itemRef) {
                    $validator->errors()->add(
                        "lines.{$index}.substituted_for_item_id",
                        'The substituted item must be the item on the selected purchase order line.',
                    );
                }

                if (is_string($itemId) && $itemId === $openLine->itemRef) {
                    $validator->errors()->add(
                        "lines.{$index}.item_id",
                        'Choose the substitute item that actually arrived, not the ordered item.',
                    );
                }

                $conditionNotes = $line['condition_notes'] ?? null;
                if (! is_string($conditionNotes) || trim($conditionNotes) === '') {
                    $validator->errors()->add(
                        "lines.{$index}.condition_notes",
                        'Give a reason for the substitution.',
                    );
                }
            } elseif (is_string($itemId) && $itemId !== $openLine->itemRef) {
                $validator->errors()->add("lines.{$index}.item_id", 'This item does not match the selected purchase order line.');
            }

            $delivered = $line['quantity_delivered'] ?? null;
            if (is_numeric($delivered)) {
                $deliveredByLine[$poLineId] = Quantity::add(
                    $deliveredByLine[$poLineId] ?? '0',
                    (string) $delivered,
                );
            }
        }

        foreach ($deliveredByLine as $poLineId => $delivered) {
            $openLine = $byId[$poLineId] ?? null;
            if ($openLine === null) {
                continue;
            }

            if (Quantity::compare($delivered, $openLine->remainingQty) > 0) {
                $index = null;
                foreach ($lines as $candidate => $line) {
                    if (($line['po_line_id'] ?? null) === $poLineId) {
                        $index = $candidate;
                        break;
                    }
                }

                if ($index !== null) {
                    $validator->errors()->add(
                        "lines.{$index}.quantity_delivered",
                        sprintf(
                            'This receipt exceeds the remaining purchase-order quantity of %s %s.',
                            $openLine->remainingQty,
                            $openLine->uom,
                        ),
                    );
                }
            }
        }
    }

    /**
     * @return list<OpenPurchaseOrderLineRef>
     */
    private function allOpenLines(ProcurementApi $procurement, string $facilityId, string $purchaseOrderId): array
    {
        $first = $procurement->searchOpenPurchaseOrderLines($facilityId, $purchaseOrderId, '', 1, 100);
        $lines = $first->lines;

        for ($page = 2; $page <= $first->lastPage; $page++) {
            $next = $procurement->searchOpenPurchaseOrderLines($facilityId, $purchaseOrderId, '', $page, 100);
            $lines = [...$lines, ...$next->lines];
        }

        return $lines;
    }

    private function validateBackdateReason(Validator $validator): void
    {
        $receivedAt = $this->input('received_at');

        if (! $validator->errors()->has('received_at')
            && is_string($receivedAt) && filled($receivedAt)
            && CarbonImmutable::parse($receivedAt)->lessThan(
                now()->toImmutable()->subHours(RecordGoodsReceipt::BACKDATE_REASON_THRESHOLD_HOURS),
            )
            && blank($this->input('reason'))) {
            $validator->errors()->add('reason', 'A reason is required for receipts dated more than 24 hours ago.');
        }
    }

    private function effectiveReceivedAt(): CarbonImmutable
    {
        $receivedAt = $this->input('received_at');

        return is_string($receivedAt) && filled($receivedAt)
            ? CarbonImmutable::parse($receivedAt)
            : CarbonImmutable::now();
    }
}
