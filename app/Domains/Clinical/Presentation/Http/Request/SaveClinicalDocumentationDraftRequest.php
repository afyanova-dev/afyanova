<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveClinicalDocumentationDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        return match ((string) $this->input('section')) {
            'NOTE' => $user->can('clinical.notes.create')
                && $user->can('clinical.consultations.create')
                && $user->can('encounter.encounters.read')
                && $user->can('patient.patients.read'),
            'DIAGNOSIS' => $user->can('clinical.diagnoses.record')
                && $user->can('encounter.encounters.read')
                && $user->can('patient.patients.read'),
            'PLAN' => $user->can('clinical.plans.record')
                && $user->can('encounter.encounters.read')
                && $user->can('patient.patients.read'),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(['NOTE', 'DIAGNOSIS', 'PLAN'])],
            'patient_id' => ['required', 'uuid'],
            'values' => ['required', 'array'],
            'values.note_type' => ['nullable', 'string', 'max:100'],
            'values.title' => ['nullable', 'string', 'max:200'],
            'values.body' => ['nullable', 'string', 'max:20000'],
            'values.description' => ['nullable', 'string', 'max:2000'],
            'values.code' => ['nullable', 'string', 'max:100'],
            'values.code_system' => ['nullable', 'string', 'max:100'],
            'values.clinical_status' => ['nullable', 'string', 'max:100'],
            'values.plan_type' => ['nullable', 'string', 'max:100'],
            'values.clinical_intent' => ['nullable', 'string', 'max:2000'],
            'values.goals' => ['nullable', 'string', 'max:2000'],
            'values.planned_actions' => ['nullable', 'string', 'max:5000'],
            'values.instructions' => ['nullable', 'string', 'max:5000'],
            'values.follow_up' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
