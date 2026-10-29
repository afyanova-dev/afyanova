<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a pending stock adjustment (18-inventory-and-warehousing-domain.md
 * §94, §95; ADR-170 D-H). A decision note is required, matching the HR
 * assignment-rejection precedent.
 */
final class RejectStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustments.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
