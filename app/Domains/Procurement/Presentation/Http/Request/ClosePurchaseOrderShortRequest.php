<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/** Close a purchase order short — the reason is mandatory (P1-10). */
final class ClosePurchaseOrderShortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.orders.amend') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->input('reason'))
                ? trim($this->input('reason'))
                : $this->input('reason'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
