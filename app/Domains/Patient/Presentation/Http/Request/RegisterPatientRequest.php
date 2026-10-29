<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Request;

use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->can('patient.patients.create')) {
            return false;
        }

        if ($this->boolean('override_duplicate') && ! $user->can('patient.patients.override_duplicate')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'given_name' => ['required', 'string', 'max:100'],
            'family_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'sex' => ['required', new Enum(Sex::class)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'dob_precision' => ['required', new Enum(DobPrecision::class)],
            'estimated_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'estimated_months' => ['nullable', 'integer', 'min:0', 'max:11'],
            'estimated_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'primary_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'insurance_member_number' => ['nullable', 'string', 'max:50'],
            'provisional' => ['sometimes', 'boolean'],
            'override_duplicate' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'required_if:override_duplicate,true,1', 'max:500'],
            'region' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'ward' => ['nullable', 'string', 'max:255'],
            'street_or_village' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'after_registration' => ['sometimes', 'string', 'in:FRONT_DESK,PATIENT_CHART,PATIENT_LIST'],
            'return_to_reception' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
