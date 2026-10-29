<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecordPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('pharmacy.pickup.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pharmacy_order_id' => ['required', 'uuid'],
            'dispensing_id' => ['required', 'uuid'],
            'recipient_type' => ['required', 'string', 'in:PATIENT,GUARDIAN,AUTHORIZED_REP,WARD,CLINICAL_UNIT'],
            'recipient_name' => ['nullable', 'string', 'max:200'],
            'verification_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
