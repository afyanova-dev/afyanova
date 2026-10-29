<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class DefineStockLocationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.location_types.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:200'],
        ];
    }
}
