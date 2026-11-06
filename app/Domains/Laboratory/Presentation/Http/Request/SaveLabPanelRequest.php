<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class SaveLabPanelRequest extends FormRequest
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
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'member_test_ids' => ['required', 'array', 'list', 'min:1'],
            'member_test_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('lab_test_definitions', 'id')
                    ->where('tenant_id', $this->user()?->tenant_id)
                    ->where('active', true)
                    ->where('availability_status', LabTestDefinition::AVAILABILITY_AVAILABLE),
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     code: string, name: string, category: string|null, description: string|null,
     *     member_test_ids: list<string>
     * }
     */
    public function dataForCreate(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            ...$this->validatedPanelAttributes(),
        ];
    }

    /**
     * @return array{
     *     name: string, category: string|null, description: string|null,
     *     member_test_ids: list<string>, active?: bool
     * }
     */
    public function dataForUpdate(): array
    {
        $attributes = $this->validatedPanelAttributes();
        $data = $this->validated();

        if (array_key_exists('active', $data)) {
            $attributes['active'] = (bool) $data['active'];
        }

        return $attributes;
    }

    /**
     * @return array{
     *     name: string, category: string|null, description: string|null,
     *     member_test_ids: list<string>, active?: bool
     * }
     */
    private function validatedPanelAttributes(): array
    {
        $data = $this->validated();

        if (! is_string($data['name'] ?? null) || ! is_array($data['member_test_ids'] ?? null)) {
            throw new LogicException('Validated laboratory panel input did not match its request contract.');
        }

        $memberTestIds = [];
        foreach (array_values($data['member_test_ids']) as $memberTestId) {
            if (! is_string($memberTestId)) {
                throw new LogicException('Validated laboratory panel membership did not match its request contract.');
            }

            $memberTestIds[] = $memberTestId;
        }

        return [
            'name' => $data['name'],
            'category' => is_string($data['category'] ?? null) ? $data['category'] : null,
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'member_test_ids' => $memberTestIds,
        ];
    }
}
