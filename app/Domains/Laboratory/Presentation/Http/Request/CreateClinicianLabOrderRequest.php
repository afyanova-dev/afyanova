<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateClinicianLabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('laboratory.orders.create') === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'submit_idempotency_key' => ['required', 'uuid'],
            'patient_id' => ['required', 'uuid'],
            'test_definition_ids' => ['sometimes', 'array', 'max:100'],
            'test_definition_ids.*' => ['required', 'uuid', 'distinct'],
            'panel_ids' => ['sometimes', 'array', 'max:100'],
            'panel_ids.*' => ['required', 'uuid', 'distinct'],
            'priority' => ['nullable', 'string', 'max:100'],
            'clinical_indication_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('test_definition_ids')
                    || $validator->errors()->has('panel_ids')
                ) {
                    return;
                }

                if (($this->input('test_definition_ids') ?? []) === [] && ($this->input('panel_ids') ?? []) === []) {
                    $validator->errors()->add('test_definition_ids', 'Select at least one lab test or panel.');
                }
            },
        ];
    }
}
