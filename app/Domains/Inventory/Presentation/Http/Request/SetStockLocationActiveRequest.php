<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class SetStockLocationActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.locations.manage') ?? false;
    }

    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }
}
