<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class AddStockCountLineRequest extends FormRequest
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
            'item_id' => ['required', 'uuid'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
