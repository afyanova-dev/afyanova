<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class SetInventoryItemActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('inventory.items.manage') ?? false)
            && app(TenantContext::class)->isGeneral();
    }

    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }
}
