<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RecordPrescribingDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinical.prescribing.record') === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'patient_id' => ['required', 'uuid'],
            'medication_name' => ['required', 'string', 'max:500'],
            'medication_code' => ['nullable', 'string', 'max:100', 'required_with:medication_code_system'],
            'medication_code_system' => ['nullable', 'string', 'max:100', 'required_with:medication_code'],
            'dose' => ['nullable', 'string', 'max:500'],
            'route' => ['nullable', 'string', 'max:100'],
            'frequency' => ['nullable', 'string', 'max:100'],
            'duration' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'string', 'max:500', 'prohibits:quantity_value'],
            'quantity_value' => ['nullable', 'numeric', 'gt:0', 'prohibits:quantity'],
            'quantity_unit' => ['nullable', 'string', 'max:100', 'required_with:quantity_value'],
            'dosing_instruction' => ['nullable', 'string', 'max:2000'],
            'therapeutic_intent' => ['nullable', 'string', 'max:500'],
            'indication' => ['nullable', 'string', 'max:1000'],
            'urgency' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'clinical_plan_id' => ['nullable', 'uuid'],
            'indication_diagnosis_id' => ['nullable', 'uuid'],
            'indication_problem_id' => ['nullable', 'uuid'],
        ];
    }
}
