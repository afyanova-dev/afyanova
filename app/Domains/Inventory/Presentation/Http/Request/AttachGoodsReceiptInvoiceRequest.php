<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class AttachGoodsReceiptInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.receipts.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['invoice_number', 'invoice_currency'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_currency' => ['required', 'string', 'size:3', 'in:TZS'],
            'invoice_total' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
