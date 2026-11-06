<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RecordLabExecutionMaterialUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionKey('laboratory.results.enter') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'use_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'batch_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
