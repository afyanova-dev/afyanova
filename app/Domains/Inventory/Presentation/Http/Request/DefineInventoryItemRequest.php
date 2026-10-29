<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Domain\ValueObject\ItemCategory;
use App\Domains\Inventory\Domain\ValueObject\ItemType;
use App\Domains\Inventory\Domain\ValueObject\UnitOfMeasure;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class DefineInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('inventory.items.manage') ?? false)
            && app(TenantContext::class)->isGeneral();
    }

    /**
     * Normalise the controlled-vocabulary fields before validation so the
     * pickers may send any case (the domain asserts upper-case anyway).
     */
    protected function prepareForValidation(): void
    {
        $normalise = static fn (mixed $value): mixed => is_string($value) ? strtoupper(trim($value)) : $value;

        $category = $normalise($this->input('category'));

        $this->merge([
            'item_type' => $normalise($this->input('item_type')),
            'uom' => $normalise($this->input('uom')),
            'category' => $category === '' ? null : $category,
            'pack_unit' => $normalise($this->input('pack_unit')),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:200'],
            'item_type' => ['required', 'string', Rule::in(ItemType::ALL)],
            'uom' => ['required', 'string', Rule::in(UnitOfMeasure::ALL)],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', Rule::in(ItemCategory::ALL)],
            'track_batch' => ['sometimes', 'boolean'],
            'track_serial' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'is_controlled' => ['sometimes', 'boolean'],
            'pack_unit' => ['nullable', 'string', Rule::in(UnitOfMeasure::ALL)],
            'pack_size' => ['nullable', 'numeric', 'decimal:0,4', 'gt:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('track_expiry') && ! $this->boolean('track_batch')) {
                    $validator->errors()->add('track_batch', 'Batch tracking is required when expiry tracking is enabled.');
                }

                // The pack is a pair (item-model rule): a buying unit AND how
                // many base units it holds, or neither.
                $packUnit = $this->input('pack_unit');
                $packSize = $this->input('pack_size');
                $hasUnit = is_string($packUnit) && trim($packUnit) !== '';
                $hasSize = $packSize !== null && trim((string) $packSize) !== '';

                if ($hasUnit !== $hasSize) {
                    $field = $hasUnit ? 'pack_size' : 'pack_unit';
                    $validator->errors()->add(
                        $field,
                        'Give the buying unit and how many base units it holds, or leave both blank.',
                    );
                }

                if ($hasUnit && ! $validator->errors()->has('pack_unit')
                    && strtoupper(trim((string) $packUnit)) === strtoupper(trim((string) $this->input('uom')))) {
                    $validator->errors()->add(
                        'pack_unit',
                        'The buying unit must differ from the base unit — a pack of one base unit is not a pack.',
                    );
                }
            },
        ];
    }
}
