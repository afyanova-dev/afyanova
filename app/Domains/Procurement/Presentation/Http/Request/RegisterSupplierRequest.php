<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register a supplier (19-procurement-domain.md §10, §13; ADR-171 D-D).
 *
 * The form sends categories as one comma-separated field (the open-coded §168
 * list), so they are split, upper-cased, de-duplicated and emptied here — the
 * domain asserts the resulting list again.
 */
final class RegisterSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('procurement.suppliers.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code'))
                ? strtoupper(trim($this->input('code')))
                : $this->input('code'),
            'categories' => $this->normaliseCategories($this->input('categories')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'licence_number' => ['nullable', 'string', 'max:100'],
            'licence_authority' => ['nullable', 'string', 'max:200'],
            'licence_expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_identifier' => ['nullable', 'string', 'max:50'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return list<string>
     */
    private function normaliseCategories(mixed $value): array
    {
        $items = is_string($value)
            ? explode(',', $value)
            : (is_array($value) ? $value : []);

        $normalised = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = strtoupper(trim($item));

            if ($trimmed !== '') {
                $normalised[$trimmed] = true;
            }
        }

        return array_keys($normalised);
    }
}
