<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Domain\Model\SupplierReturn;
use Illuminate\Foundation\Http\FormRequest;

final class ReturnGoodsToSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.receipts.create') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'return_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'in:'.implode(',', [
                SupplierReturn::REASON_DAMAGED_AFTER_RECEIPT,
                SupplierReturn::REASON_WRONG_ITEM,
                SupplierReturn::REASON_EXPIRED,
            ])],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.receipt_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999', 'decimal:0,4'],
            'lines.*.credit_note_ref' => ['nullable', 'string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
