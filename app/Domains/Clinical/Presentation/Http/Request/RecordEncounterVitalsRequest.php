<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class RecordEncounterVitalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.vitals.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'temperature' => ['nullable', 'numeric', 'between:20,45'],
            'heart_rate' => ['nullable', 'integer', 'between:0,350'],
            'respiratory_rate' => ['nullable', 'integer', 'between:0,150'],
            'systolic_bp' => ['nullable', 'integer', 'between:0,300'],
            'diastolic_bp' => ['nullable', 'integer', 'between:0,250'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:0,100'],
            'weight' => ['nullable', 'numeric', 'between:0,500'],
            'height' => ['nullable', 'numeric', 'between:0,300'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $measurements = [
                'temperature', 'heart_rate', 'respiratory_rate', 'systolic_bp',
                'diastolic_bp', 'oxygen_saturation', 'weight', 'height', 'pain_score',
            ];
            $hasMeasurement = false;
            foreach ($measurements as $field) {
                if ($this->input($field) !== null && $this->input($field) !== '') {
                    $hasMeasurement = true;
                    break;
                }
            }
            if (! $hasMeasurement) {
                $validator->errors()->add('temperature', 'Record at least one vital sign.');
            }
        });
    }
}
