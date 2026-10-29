<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class RecordClinicianDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.diagnoses.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:100'],
            'code_system' => ['nullable', 'string', 'max:100'],
            'clinical_status' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $code = $this->input('code');
            $codeSystem = $this->input('code_system');
            $hasCode = is_string($code) && trim($code) !== '';
            $hasCodeSystem = is_string($codeSystem) && trim($codeSystem) !== '';

            if ($hasCode !== $hasCodeSystem) {
                $validator->errors()->add('code_system', 'Provide both a diagnosis code and its code system, or leave both blank.');
            }
        });
    }
}
