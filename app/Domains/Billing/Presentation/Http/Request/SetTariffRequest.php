<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class SetTariffRequest extends FormRequest
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
        // A tariff row is immutable history: this mints a NEW row (the prior
        // open row is closed by the handler). There is no "edit price".
        // Domain requires a strictly positive amount (free services are not
        // representable as a TZS tariff — use NO_CHARGE handling instead).
        return [
            'billing_service_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'facility_scope' => ['nullable', 'boolean'],
        ];
    }
}
