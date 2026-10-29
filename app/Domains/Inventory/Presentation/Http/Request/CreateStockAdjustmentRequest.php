<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Application\Query\GetItems;
use App\Shared\Bus\QueryBus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validate a stock-adjustment request (18-inventory-and-warehousing-domain.md
 * §49, §94, §95; ADR-170 D-H). The handler remains authoritative: this only
 * mirrors its preconditions into form errors.
 */
final class CreateStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'direction' => ['required', 'in:INCREASE,DECREASE'],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'reason_code' => ['required', 'in:COUNT_VARIANCE,DAMAGED,LOST,DATA_ERROR,EXPIRY,OTHER'],
            'reason_text' => ['nullable', 'string', 'max:500'],
            'witness_name' => ['nullable', 'string', 'max:120'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'adjustment_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $itemId = $this->input('item_id');
                if ($validator->errors()->has('item_id') || ! is_string($itemId)) {
                    return;
                }

                $items = app(QueryBus::class)->dispatch(new GetItems(active: true));
                $item = collect($items)->first(fn ($candidate): bool => $candidate->itemId === $itemId);

                if ($item === null) {
                    return;
                }

                if ($item->trackSerial) {
                    $validator->errors()->add(
                        'item_id',
                        'Serial-tracked items cannot be adjusted through this workflow yet.',
                    );
                }

                if ($item->trackBatch && blank($this->input('batch_number'))) {
                    $validator->errors()->add('batch_number', 'A batch number is required for this item.');
                }

                // Controlled medicine: the domain refuses a DECREASE without a
                // witness (ControlledStockWitnessRequired); mirror it here so
                // the panel can point at the field.
                if ($item->isControlled
                    && strtoupper((string) $this->input('direction')) === 'DECREASE'
                    && blank($this->input('witness_name'))) {
                    $validator->errors()->add(
                        'witness_name',
                        'This item is a controlled medicine: name the witness who watched the stock leave.',
                    );
                }
            },
        ];
    }
}
