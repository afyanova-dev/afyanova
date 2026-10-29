<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a DRAFT purchase order from the workspace (19-procurement-domain.md
 * §43–§51; ADR-171 D-H).
 *
 * Shape B only raises DIRECT orders from this screen: supplier + one or more
 * stock lines. A request (`requisitionId`), an RFQ award and a contract are all
 * valid sources of an order but each needs its own screen, so they are not
 * accepted here — the API stays honest about what the form can express.
 */
final class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.orders.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['cost_centre_ref', 'budget_ref', 'duplicate_override_reason'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            // Optional: raise the order for an APPROVED request. The request's
            // department is what selects that department's approval limit.
            'requisition_id' => ['nullable', 'uuid'],
            'priority' => ['required', 'string', Rule::in(['ROUTINE', 'URGENT'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cost_centre_ref' => ['nullable', 'string', 'max:100'],
            'budget_ref' => ['nullable', 'string', 'max:100'],
            // P2-03 — only used when the server detects an exact repeat.
            'duplicate_override_reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // The unit the buyer typed in (blank = the item's base unit) and,
            // when it is a buying unit, how many base units are in one. The
            // domain resolves both against the item — these rules only guard
            // the shape of the request.
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.pack_size' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
