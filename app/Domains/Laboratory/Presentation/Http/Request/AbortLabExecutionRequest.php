<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class AbortLabExecutionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
