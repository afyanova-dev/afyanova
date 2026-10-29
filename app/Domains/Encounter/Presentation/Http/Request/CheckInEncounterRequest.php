<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Request;

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CheckInEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('encounter.encounters.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'encounter_type' => ['required', new Enum(EncounterType::class)],
            'department_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'queue_id' => ['nullable', 'uuid'],
            'protocol_procedure_definition_id' => ['nullable', 'uuid'],
            'protocol_procedure_indication' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', 'in:ROUTINE,URGENT,EMERGENCY'],
            'appointment_id' => ['nullable', 'uuid'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
