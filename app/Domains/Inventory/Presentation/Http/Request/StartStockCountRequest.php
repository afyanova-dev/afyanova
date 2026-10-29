<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class StartStockCountRequest extends FormRequest
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
            'stock_count_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
