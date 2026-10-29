<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DispenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('pharmacy.dispensing.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pharmacy_order_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'batch_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
