<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Map an `inv_items.id` to a billing service (ADR-189 D-A). The ids are
 * validated here; the handler re-checks the service exists + is ACTIVE.
 */
final class MapItemBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('billing.catalog.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            'billing_service_id' => ['required', 'uuid'],
        ];
    }
}
