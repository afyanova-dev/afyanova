<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Application\Command\TransferStock;
use App\Domains\Inventory\Application\Query\GetItems;
use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Shared\Bus\QueryBus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfers.create') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'transfer_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid'],
            'from_location_id' => ['required', 'uuid'],
            'to_location_id' => ['required', 'uuid', 'different:from_location_id'],
            // Base units only — the pack size is a display help, never a second
            // truth (Phase 8 pack rule).
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_location_id.different' => 'Choose a destination different from the location the stock is in.',
            'quantity.decimal' => 'Enter the quantity in stock units, to at most 4 decimal places.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $itemId = $this->input('item_id');

                if (! $validator->errors()->has('item_id') && is_string($itemId)) {
                    /** @var list<ItemSummary> $items */
                    $items = app(QueryBus::class)->dispatch(new GetItems(active: true));
                    $item = null;
                    foreach ($items as $candidate) {
                        if ($candidate->itemId === $itemId) {
                            $item = $candidate;
                            break;
                        }
                    }

                    if ($item !== null && $item->trackSerial) {
                        $validator->errors()->add(
                            'item_id',
                            'Serial-tracked items cannot be moved through this workflow yet.',
                        );
                    }

                    if ($item !== null && $item->trackBatch && blank($this->input('batch_number'))) {
                        $validator->errors()->add('batch_number', 'A batch number is required for this item.');
                    }
                }

                $occurredAt = $this->input('occurred_at');

                if (! $validator->errors()->has('occurred_at')
                    && is_string($occurredAt) && filled($occurredAt)
                    && CarbonImmutable::parse($occurredAt)->lessThan(
                        now()->toImmutable()->subHours(TransferStock::BACKDATE_REASON_THRESHOLD_HOURS),
                    )
                    && blank($this->input('reason'))) {
                    $validator->errors()->add(
                        'reason',
                        'A reason is required for transfers dated more than 24 hours ago.',
                    );
                }
            },
        ];
    }
}
