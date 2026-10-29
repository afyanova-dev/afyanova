<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve a pending stock adjustment (18-inventory-and-warehousing-domain.md
 * §94, §95, §224; ADR-170 D-H). The handler stays authoritative on the
 * lifecycle and separation-of-duties checks.
 */
final class ApproveStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustments.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
