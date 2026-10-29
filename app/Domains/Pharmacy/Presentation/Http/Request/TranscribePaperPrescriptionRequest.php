<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class TranscribePaperPrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('pharmacy.prescriptions.accept');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'encounter_id' => ['prohibited'],
            'source' => ['required', 'string', 'in:EXTERNAL_PAPER'],
            'prescriber_name' => ['required', 'string', 'min:1', 'max:200'],
            'transcription_note' => ['nullable', 'string', 'max:1000'],
            'external_intake_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.prescription_id' => ['required', 'uuid', 'distinct'],
            'lines.*.pharmacy_order_id' => ['required', 'uuid', 'distinct'],
            'lines.*.inventory_item_id' => ['required', 'uuid'],
            'lines.*.medication_name' => ['prohibited'],
            'lines.*.medication_code' => ['prohibited'],
            'lines.*.dose' => ['nullable', 'string', 'max:120'],
            'lines.*.frequency' => ['nullable', 'string', 'max:120'],
            'lines.*.duration' => ['nullable', 'string', 'max:120'],
            'lines.*.prescribed_quantity' => ['nullable', 'string', 'max:60'],
            'lines.*.dosing_instruction' => ['nullable', 'string', 'max:1000'],
            'lines.*.route' => ['nullable', 'string', 'max:60'],
            'lines.*.urgency' => ['nullable', 'string', 'max:20'],
        ];
    }
}
