<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSupplierLicenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.suppliers.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'licence_number' => ['nullable', 'string', 'max:100'],
            'licence_authority' => ['nullable', 'string', 'max:200'],
            'licence_expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
