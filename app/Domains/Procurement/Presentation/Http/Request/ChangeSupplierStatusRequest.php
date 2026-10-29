<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use App\Domains\Procurement\Domain\ValueObject\SupplierStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a supplier's lifecycle status (19-procurement-domain.md §12, §14, §15).
 *
 * The same endpoint carries BLACKLISTED — the controller routes it to the
 * distinct `BlacklistSupplier` command — because from the operator's point of
 * view it is still "move this supplier to that status, with a reason".
 */
final class ChangeSupplierStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.suppliers.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_status' => is_string($this->input('target_status'))
                ? strtoupper(trim($this->input('target_status')))
                : $this->input('target_status'),
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
        return [
            'target_status' => ['required', 'string', Rule::in(SupplierStatus::ALL)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
