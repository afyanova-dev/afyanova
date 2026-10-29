<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterExternalPharmacyPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('pharmacy.prescriptions.accept')
            && $user->can('patient.patients.create_pharmacy_only');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'given_name' => ['required', 'string', 'min:1', 'max:120'],
            'family_name' => ['required', 'string', 'min:1', 'max:120'],
            'sex' => ['required', 'string', Rule::in(['MALE', 'FEMALE', 'UNKNOWN'])],
            'primary_phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
