<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Domain\Model\StockCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CloseStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.counts.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([StockCount::COMPLETION_KEEP, StockCount::COMPLETION_DISCARD])],
            // Required by the server only when a controlled medicine has a
            // shortfall; the sheet asks for it and the nested adjustment command
            // enforces the rule.
            'witness_name' => ['nullable', 'string', 'max:120'],
            'counts' => ['sometimes', 'array'],
            'counts.*' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
