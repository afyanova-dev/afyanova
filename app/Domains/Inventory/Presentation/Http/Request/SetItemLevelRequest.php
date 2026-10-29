<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class SetItemLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('inventory.items.manage') ?? false)
            && app(TenantContext::class)->facilityId() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            // 0 clears the level ("stop tracking"); it never means "reorder at
            // zero". The level is in the item's base unit, like every stock
            // quantity.
            'min_level' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_level.required' => 'Enter the minimum level, or choose Stop tracking to clear it.',
            'min_level.numeric' => 'The minimum level must be a number of stock units.',
            'min_level.min' => 'The minimum level cannot be negative.',
        ];
    }
}
