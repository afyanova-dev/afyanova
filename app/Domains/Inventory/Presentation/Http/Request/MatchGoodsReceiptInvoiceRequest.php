<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class MatchGoodsReceiptInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.invoices.match') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.receipt_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.invoice_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999.9999', 'decimal:0,4'],
            'lines.*.invoice_unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
        ];
    }
}
