<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class RecordEncounterTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.triage.record');
    }

    protected function prepareForValidation(): void
    {
        $priority = $this->input('triage_priority');
        if (is_string($priority)) {
            $this->merge(['triage_priority' => Str::upper(trim($priority))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'chief_complaint' => ['required', 'string', 'max:2000'],
            'triage_priority' => ['required', 'string', 'in:'.implode(',', TriagePriority::ALL)],
            'acuity' => ['nullable', 'string', 'max:100'],
            'consciousness' => ['nullable', 'string', 'max:100'],
            'source_vitals_observation_id' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
