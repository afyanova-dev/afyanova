<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use App\Domains\Laboratory\Domain\Model\LabMethodInventoryRequirement;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class SaveLabTestMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', LabOrder::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'specimen_type' => ['nullable', 'string', 'max:100'],
            'container_type' => ['nullable', 'string', 'max:100'],
            'active' => [$this->isMethod('put') ? 'required' : 'sometimes', 'boolean'],
            'requirements' => ['present', 'array', 'max:100'],
            'requirements.*' => ['required', 'array:inventory_item_id,role,required,usage_scope,quantity'],
            'requirements.*.inventory_item_id' => ['required', 'uuid', 'distinct'],
            'requirements.*.role' => ['required', Rule::in(LabMethodInventoryRequirement::ROLES)],
            'requirements.*.required' => ['required', 'boolean'],
            'requirements.*.usage_scope' => ['required', Rule::in(LabMethodInventoryRequirement::USAGE_SCOPES)],
            'requirements.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
                'regex:/\A\d{1,14}(?:\.\d{1,4})?\z/',
            ],
        ];
    }

    /**
     * @return array{
     *     code: string, name: string, description: string|null,
     *     specimen_type: string|null, container_type: string|null,
     *     requirements: list<array{
     *         inventory_item_id: string, role: string, required: bool,
     *         usage_scope: string, quantity: string
     *     }>
     * }
     */
    public function dataForCreate(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            ...$this->validatedMethodAttributes(),
        ];
    }

    /**
     * @return array{
     *     name: string, description: string|null,
     *     specimen_type: string|null, container_type: string|null,
     *     active: bool,
     *     requirements: list<array{
     *         inventory_item_id: string, role: string, required: bool,
     *         usage_scope: string, quantity: string
     *     }>
     * }
     */
    public function dataForUpdate(): array
    {
        return [
            ...$this->validatedMethodAttributes(),
            'active' => (bool) $this->validated('active'),
        ];
    }

    /**
     * @return array{
     *     name: string, description: string|null,
     *     specimen_type: string|null, container_type: string|null,
     *     requirements: list<array{
     *         inventory_item_id: string, role: string, required: bool,
     *         usage_scope: string, quantity: string
     *     }>
     * }
     */
    private function validatedMethodAttributes(): array
    {
        $data = $this->validated();

        if (! is_string($data['name'] ?? null) || ! is_array($data['requirements'] ?? null)) {
            throw new LogicException('Validated laboratory method input did not match its request contract.');
        }

        $requirements = [];
        foreach (array_values($data['requirements']) as $requirement) {
            if (! is_array($requirement)) {
                throw new LogicException('Validated laboratory material input did not match its request contract.');
            }

            $requirements[] = [
                'inventory_item_id' => (string) ($requirement['inventory_item_id'] ?? ''),
                'role' => (string) ($requirement['role'] ?? ''),
                'required' => (bool) ($requirement['required'] ?? false),
                'usage_scope' => (string) ($requirement['usage_scope'] ?? ''),
                'quantity' => (string) ($requirement['quantity'] ?? ''),
            ];
        }

        return [
            'name' => $data['name'],
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'specimen_type' => is_string($data['specimen_type'] ?? null) ? $data['specimen_type'] : null,
            'container_type' => is_string($data['container_type'] ?? null) ? $data['container_type'] : null,
            'requirements' => $requirements,
        ];
    }
}
