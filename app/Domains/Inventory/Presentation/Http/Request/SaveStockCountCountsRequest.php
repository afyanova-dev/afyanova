<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class SaveStockCountCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.counts.manage') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Line id → counted quantity. Blank/null clears the line back to
            // "not counted"; 0 is a real count (the shelf is empty).
            'counts' => ['present', 'array'],
            'counts.*' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counts.*.numeric' => 'Each counted quantity must be a number of stock units.',
            'counts.*.min' => 'A counted quantity cannot be negative.',
        ];
    }
}
