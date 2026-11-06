<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class StartLabExecutionRequest extends FormRequest
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
            'execution_id' => ['required', 'uuid'],
            'specimen_id' => ['required', 'uuid'],
            'method_id' => ['required', 'uuid'],
            'instrument_id' => ['nullable', 'uuid'],
        ];
    }
}
