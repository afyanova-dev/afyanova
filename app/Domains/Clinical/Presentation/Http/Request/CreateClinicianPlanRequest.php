<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class CreateClinicianPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.plans.record');
    }

    protected function prepareForValidation(): void
    {
        $planType = $this->input('plan_type');
        if (is_string($planType)) {
            $this->merge(['plan_type' => Str::upper(trim($planType))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'plan_type' => ['required', 'string', 'in:'.implode(',', ClinicalPlanType::ALL)],
            'clinical_intent' => ['required', 'string', 'max:2000'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'planned_actions' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
